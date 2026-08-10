<?php

use App\Jobs\DockerCleanupJob;
use App\Models\DockerCleanupExecution;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '10.0.0.10',
    ]);

    $otherTeam = Team::factory()->create();
    $this->otherServer = Server::factory()->create([
        'team_id' => $otherTeam->id,
        'ip' => '10.0.0.20',
    ]);

    $this->token = $this->user->createToken('server-subsystems', ['*'])->plainTextToken;
});

function serverSubsystemsHeaders(): array
{
    return [
        'Authorization' => 'Bearer '.test()->token,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
}

describe('Docker cleanup API', function () {
    test('GET returns docker cleanup settings for own team server', function () {
        $this->server->settings->update([
            'docker_cleanup_frequency' => '0 0 * * *',
            'docker_cleanup_threshold' => 25,
            'force_docker_cleanup' => true,
            'delete_unused_volumes' => true,
            'delete_unused_networks' => false,
            'disable_application_image_retention' => true,
        ]);

        $this->withHeaders(serverSubsystemsHeaders())
            ->getJson("/api/v1/servers/{$this->server->uuid}/docker-cleanup")
            ->assertOk()
            ->assertJsonPath('docker_cleanup_threshold', 25)
            ->assertJsonPath('force_docker_cleanup', true)
            ->assertJsonPath('delete_unused_volumes', true)
            ->assertJsonPath('disable_application_image_retention', true);
    });

    test('PATCH updates docker cleanup settings for own team server', function () {
        $this->withHeaders(serverSubsystemsHeaders())
            ->patchJson("/api/v1/servers/{$this->server->uuid}/docker-cleanup", [
                'docker_cleanup_frequency' => '0 */6 * * *',
                'docker_cleanup_threshold' => 42,
                'force_docker_cleanup' => true,
                'delete_unused_volumes' => true,
                'delete_unused_networks' => true,
                'disable_application_image_retention' => true,
            ])
            ->assertOk()
            ->assertJsonPath('docker_cleanup_threshold', 42)
            ->assertJsonPath('force_docker_cleanup', true);

        $settings = $this->server->settings->fresh();
        expect($settings->docker_cleanup_threshold)->toBe(42)
            ->and((bool) $settings->force_docker_cleanup)->toBeTrue()
            ->and((bool) $settings->delete_unused_volumes)->toBeTrue()
            ->and((bool) $settings->delete_unused_networks)->toBeTrue()
            ->and((bool) $settings->disable_application_image_retention)->toBeTrue();
    });

    test('POST run dispatches DockerCleanupJob for own team server', function () {
        Queue::fake();

        $this->server->settings->update([
            'delete_unused_volumes' => true,
            'delete_unused_networks' => false,
        ]);

        $this->withHeaders(serverSubsystemsHeaders())
            ->postJson("/api/v1/servers/{$this->server->uuid}/docker-cleanup/run")
            ->assertOk()
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'Manual cleanup job started'));

        Queue::assertPushed(DockerCleanupJob::class, function (DockerCleanupJob $job) {
            return $job->server->is($this->server)
                && $job->manualCleanup === true
                && $job->deleteUnusedVolumes === true
                && $job->deleteUnusedNetworks === false;
        });
    });

    test('GET executions lists recent cleanup runs for own team server', function () {
        $execution = DockerCleanupExecution::create([
            'server_id' => $this->server->id,
            'status' => 'success',
            'message' => 'Cleanup completed',
        ]);

        $this->withHeaders(serverSubsystemsHeaders())
            ->getJson("/api/v1/servers/{$this->server->uuid}/docker-cleanup/executions")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.uuid', $execution->uuid)
            ->assertJsonPath('0.status', 'success');
    });

    test('other-team docker cleanup endpoints return 404', function () {
        $this->withHeaders(serverSubsystemsHeaders())
            ->getJson("/api/v1/servers/{$this->otherServer->uuid}/docker-cleanup")
            ->assertNotFound();

        $this->withHeaders(serverSubsystemsHeaders())
            ->patchJson("/api/v1/servers/{$this->otherServer->uuid}/docker-cleanup", [
                'docker_cleanup_threshold' => 50,
            ])
            ->assertNotFound();

        $this->withHeaders(serverSubsystemsHeaders())
            ->postJson("/api/v1/servers/{$this->otherServer->uuid}/docker-cleanup/run")
            ->assertNotFound();

        $this->withHeaders(serverSubsystemsHeaders())
            ->getJson("/api/v1/servers/{$this->otherServer->uuid}/docker-cleanup/executions")
            ->assertNotFound();
    });
});

describe('Log drains API', function () {
    test('GET returns log drain settings and hides secrets without read:sensitive', function () {
        $this->server->settings->update([
            'is_logdrain_axiom_enabled' => false,
            'logdrain_axiom_dataset_name' => 'coolify-logs',
            'logdrain_axiom_api_key' => 'secret-axiom-key',
            'logdrain_newrelic_license_key' => 'secret-nr-key',
        ]);

        $readToken = $this->user->createToken('server-subsystems-read', ['read'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$readToken,
            'Accept' => 'application/json',
        ])
            ->getJson("/api/v1/servers/{$this->server->uuid}/log-drains")
            ->assertOk()
            ->assertJsonPath('logdrain_axiom_dataset_name', 'coolify-logs')
            ->assertJsonPath('is_logdrain_axiom_enabled', false);

        expect($response->json())->not->toHaveKey('logdrain_axiom_api_key')
            ->and($response->json())->not->toHaveKey('logdrain_newrelic_license_key');
    });

    test('PATCH updates log drain settings for own team server', function () {
        Queue::fake();

        $this->withHeaders(serverSubsystemsHeaders())
            ->patchJson("/api/v1/servers/{$this->server->uuid}/log-drains", [
                'logdrain_axiom_dataset_name' => 'api-dataset',
                'logdrain_axiom_api_key' => 'axiom-key-123',
            ])
            ->assertOk()
            ->assertJsonPath('logdrain_axiom_dataset_name', 'api-dataset');

        $settings = $this->server->settings->fresh();
        expect($settings->logdrain_axiom_dataset_name)->toBe('api-dataset')
            ->and($settings->logdrain_axiom_api_key)->toBe('axiom-key-123');
    });

    test('other-team log drains endpoints return 404', function () {
        $this->withHeaders(serverSubsystemsHeaders())
            ->getJson("/api/v1/servers/{$this->otherServer->uuid}/log-drains")
            ->assertNotFound();

        $this->withHeaders(serverSubsystemsHeaders())
            ->patchJson("/api/v1/servers/{$this->otherServer->uuid}/log-drains", [
                'logdrain_axiom_dataset_name' => 'nope',
            ])
            ->assertNotFound();
    });
});

describe('Sentinel API', function () {
    test('GET returns sentinel settings without token without read:sensitive', function () {
        // Avoid fields that trigger restartSentinel() on save (token/url/metrics timing).
        $this->server->settings->update([
            'is_sentinel_enabled' => true,
            'is_metrics_enabled' => true,
            'is_sentinel_debug_enabled' => false,
        ]);

        $readToken = $this->user->createToken('server-subsystems-read', ['read'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$readToken,
            'Accept' => 'application/json',
        ])
            ->getJson("/api/v1/servers/{$this->server->uuid}/sentinel")
            ->assertOk()
            ->assertJsonPath('is_sentinel_enabled', true)
            ->assertJsonPath('is_metrics_enabled', true);

        expect($response->json())->not->toHaveKey('sentinel_token')
            ->and($response->json())->not->toHaveKey('sentinel_custom_url');
    });

    test('PATCH updates sentinel settings for own team server', function () {
        // Only toggle fields that do not restart Sentinel (avoids remote StartSentinel on sync queue).
        $this->withHeaders(serverSubsystemsHeaders())
            ->patchJson("/api/v1/servers/{$this->server->uuid}/sentinel", [
                'is_metrics_enabled' => true,
                'is_sentinel_debug_enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('is_metrics_enabled', true)
            ->assertJsonPath('is_sentinel_debug_enabled', true);

        $settings = $this->server->settings->fresh();
        expect((bool) $settings->is_metrics_enabled)->toBeTrue()
            ->and((bool) $settings->is_sentinel_debug_enabled)->toBeTrue();
    });

    test('other-team sentinel endpoints return 404', function () {
        $this->withHeaders(serverSubsystemsHeaders())
            ->getJson("/api/v1/servers/{$this->otherServer->uuid}/sentinel")
            ->assertNotFound();

        $this->withHeaders(serverSubsystemsHeaders())
            ->patchJson("/api/v1/servers/{$this->otherServer->uuid}/sentinel", [
                'is_metrics_enabled' => true,
            ])
            ->assertNotFound();
    });
});

describe('Cloudflare Tunnel API', function () {
    test('GET returns cloudflare tunnel settings for own team server', function () {
        $this->server->settings->update(['is_cloudflare_tunnel' => true]);

        $this->withHeaders(serverSubsystemsHeaders())
            ->getJson("/api/v1/servers/{$this->server->uuid}/cloudflare-tunnel")
            ->assertOk()
            ->assertJsonPath('is_cloudflare_tunnel', true)
            ->assertJsonPath('ip', '10.0.0.10');
    });

    test('PATCH enables cloudflare tunnel setting for own team server', function () {
        $this->withHeaders(serverSubsystemsHeaders())
            ->patchJson("/api/v1/servers/{$this->server->uuid}/cloudflare-tunnel", [
                'is_cloudflare_tunnel' => true,
            ])
            ->assertOk()
            ->assertJsonPath('is_cloudflare_tunnel', true);

        expect((bool) $this->server->settings->fresh()->is_cloudflare_tunnel)->toBeTrue();
    });

    test('POST enable and disable match manual UI actions', function () {
        // Changing ip auto-sets ip_previous to the previous IP via Server model boot.
        $originalIp = (string) $this->server->ip;
        $this->server->update(['ip' => '100.64.0.5']);
        expect((string) $this->server->fresh()->ip_previous)->toBe($originalIp);

        $this->server->settings->update(['is_cloudflare_tunnel' => false]);

        $this->withHeaders(serverSubsystemsHeaders())
            ->postJson("/api/v1/servers/{$this->server->uuid}/cloudflare-tunnel/enable")
            ->assertOk()
            ->assertJsonPath('is_cloudflare_tunnel', true);

        expect((bool) $this->server->settings->fresh()->is_cloudflare_tunnel)->toBeTrue();

        $this->withHeaders(serverSubsystemsHeaders())
            ->postJson("/api/v1/servers/{$this->server->uuid}/cloudflare-tunnel/disable")
            ->assertOk()
            ->assertJsonPath('is_cloudflare_tunnel', false)
            ->assertJsonPath('ip', $originalIp);

        expect((bool) $this->server->settings->fresh()->is_cloudflare_tunnel)->toBeFalse()
            ->and((string) $this->server->fresh()->ip)->toBe($originalIp);
    });

    test('other-team cloudflare tunnel endpoints return 404', function () {
        $this->withHeaders(serverSubsystemsHeaders())
            ->getJson("/api/v1/servers/{$this->otherServer->uuid}/cloudflare-tunnel")
            ->assertNotFound();

        $this->withHeaders(serverSubsystemsHeaders())
            ->patchJson("/api/v1/servers/{$this->otherServer->uuid}/cloudflare-tunnel", [
                'is_cloudflare_tunnel' => true,
            ])
            ->assertNotFound();

        $this->withHeaders(serverSubsystemsHeaders())
            ->postJson("/api/v1/servers/{$this->otherServer->uuid}/cloudflare-tunnel/enable")
            ->assertNotFound();

        $this->withHeaders(serverSubsystemsHeaders())
            ->postJson("/api/v1/servers/{$this->otherServer->uuid}/cloudflare-tunnel/disable")
            ->assertNotFound();
    });
});

describe('Server update is_terminal_enabled', function () {
    test('PATCH /servers/{uuid} updates is_terminal_enabled on settings', function () {
        $this->withHeaders(serverSubsystemsHeaders())
            ->patchJson("/api/v1/servers/{$this->server->uuid}", [
                'is_terminal_enabled' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('uuid', $this->server->uuid);

        expect((bool) $this->server->settings->fresh()->is_terminal_enabled)->toBeTrue();
    });

    test('PATCH /servers/{uuid} other-team returns 404', function () {
        $this->withHeaders(serverSubsystemsHeaders())
            ->patchJson("/api/v1/servers/{$this->otherServer->uuid}", [
                'is_terminal_enabled' => true,
            ])
            ->assertNotFound();
    });
});
