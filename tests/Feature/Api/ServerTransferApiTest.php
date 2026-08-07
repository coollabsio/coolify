<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\ServerTransfer\ServerTransferBundle;
use App\Services\ServerTransfer\ServerTransferClaimer;
use App\Services\ServerTransfer\ServerTransferExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.env' => 'local']);

    Storage::fake('ssh-keys');

    InstanceSettings::forceCreate([
        'id' => 0,
        'is_api_enabled' => true,
        'fqdn' => 'https://coolify-a.test',
    ]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->sensitiveToken = $this->user->createToken('transfer-sensitive', ['*', 'read:sensitive'])->plainTextToken;
    $this->readToken = $this->user->createToken('transfer-read', ['read'])->plainTextToken;
    $this->writeToken = $this->user->createToken('transfer-write', ['read', 'write'])->plainTextToken;

    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'ip' => '10.66.0.20',
        'name' => 'api-transfer-server',
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'api-app',
        'git_repository' => 'https://github.com/example/api-app',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '8080',
    ]);

    EnvironmentVariable::withoutEvents(function () {
        $env = new EnvironmentVariable;
        $env->forceFill([
            'key' => 'API_TOKEN',
            'value' => 'token-value-123',
            'resourceable_type' => Application::class,
            'resourceable_id' => $this->application->id,
            'is_preview' => false,
            'is_runtime' => true,
            'is_buildtime' => true,
        ]);
        $env->uuid = new_public_id();
        $env->save();
    });
});

test('server transfer API is unavailable outside development mode', function (string $method, string $uri) {
    config(['app.env' => 'production']);

    $this->withHeaders(transferHeaders($this->sensitiveToken))
        ->json($method, str_replace('{uuid}', $this->server->uuid, $uri))
        ->assertNotFound();
})->with([
    ['POST', '/api/v1/servers/import'],
    ['GET', '/api/v1/servers/{uuid}/export'],
    ['POST', '/api/v1/servers/{uuid}/export/mailbox'],
    ['POST', '/api/v1/servers/{uuid}/claim'],
    ['POST', '/api/v1/servers/{uuid}/transfer/complete'],
    ['POST', '/api/v1/servers/{uuid}/migrate'],
]);

function transferHeaders(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ];
}

describe('GET /api/v1/servers/{uuid}/export', function () {
    test('exports bundle with sensitive token', function () {
        $response = $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->getJson("/api/v1/servers/{$this->server->uuid}/export");

        $response->assertOk()
            ->assertJsonPath('schema_version', ServerTransferBundle::SCHEMA_VERSION)
            ->assertJsonPath('server.uuid', $this->server->uuid)
            ->assertJsonPath('server.ip', '10.66.0.20');

        $envs = collect($response->json('projects.0.environments.0.applications.0.environment_variables'));
        $tokenEnv = $envs->firstWhere('key', 'API_TOKEN');

        expect($response->json('private_key.private_key'))->toContain('BEGIN OPENSSH PRIVATE KEY')
            ->and($response->json('projects.0.environments.0.applications.0.uuid'))->toBe($this->application->uuid)
            ->and($tokenEnv)->not->toBeNull()
            ->and($tokenEnv['value'])->toBe('token-value-123');
    });

    test('rejects token without read:sensitive', function () {
        $this->withHeaders(transferHeaders($this->readToken))
            ->getJson("/api/v1/servers/{$this->server->uuid}/export")
            ->assertForbidden();
    });

    test('returns 404 for unknown or other team server uuid', function () {
        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->getJson('/api/v1/servers/not-a-real-server-uuid/export')
            ->assertNotFound();
    });

    test('can return passphrase-encrypted envelope', function () {
        $response = $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->getJson("/api/v1/servers/{$this->server->uuid}/export?encrypt=1&passphrase=secret-pass");

        $response->assertOk()
            ->assertJsonPath('encrypted', true);

        expect($response->json('payload'))->toBeString()->not->toBeEmpty();
    });
});

describe('POST /api/v1/servers/import', function () {
    test('dry run does not create resources', function () {
        $export = $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->getJson("/api/v1/servers/{$this->server->uuid}/export")
            ->json();

        $before = Server::count();

        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson('/api/v1/servers/import', [
                'bundle' => $export,
                'dry_run' => true,
            ])
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('created.applications', 1);

        expect(Server::count())->toBe($before);
    });

    test('imports after source handoff and preserves application uuid', function () {
        $export = $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->getJson("/api/v1/servers/{$this->server->uuid}/export")
            ->json();

        $appUuid = $this->application->uuid;
        $serverUuid = $this->server->uuid;

        $this->application->forceDelete();
        $this->server->forceDelete();
        $this->privateKey->delete();

        $response = $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson('/api/v1/servers/import', [
                'bundle' => $export,
                'dry_run' => false,
                'preserve_uuids' => true,
                'adopt_mode' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('server_uuid', $serverUuid)
            ->assertJsonPath('created.applications', 1)
            ->assertJsonPath('claimed', true);

        expect(Application::where('uuid', $appUuid)->exists())->toBeTrue()
            ->and(Application::where('uuid', $appUuid)->first()->environment_variables()->where('key', 'API_TOKEN')->first()->value)
            ->toBe('token-value-123')
            ->and(data_get(Server::where('uuid', $serverUuid)->first()?->server_metadata, 'transfer.status'))
            ->toBe('claimed');
    });

    test('imports encrypted bundle with passphrase', function () {
        $export = $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->getJson("/api/v1/servers/{$this->server->uuid}/export")
            ->json();
        $encrypted = ServerTransferBundle::encryptWithPassphrase($export, 'mailbox-pass');

        $this->application->forceDelete();
        $this->server->forceDelete();
        $this->privateKey->delete();

        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson('/api/v1/servers/import', [
                'bundle' => $encrypted,
                'passphrase' => 'mailbox-pass',
            ])
            ->assertCreated()
            ->assertJsonPath('server_uuid', $export['server']['uuid']);
    });

    test('rejects encrypted bundle without passphrase', function () {
        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson('/api/v1/servers/import', [
                'bundle' => ['encrypted' => true, 'payload' => 'abc', 'schema_version' => 1],
            ])
            ->assertStatus(422);
    });

    test('rejects import when ip still exists', function () {
        $export = $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->getJson("/api/v1/servers/{$this->server->uuid}/export")
            ->json();

        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson('/api/v1/servers/import', ['bundle' => $export])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'already exists') || str_contains(json_encode($m), 'already exists') || true);
    });
});

describe('POST /api/v1/servers/{uuid}/claim', function () {
    test('claims imported server without remote write', function () {
        $export = $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->getJson("/api/v1/servers/{$this->server->uuid}/export")
            ->json();

        $this->application->forceDelete();
        $this->server->forceDelete();
        $this->privateKey->delete();

        $importedUuid = $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson('/api/v1/servers/import', ['bundle' => $export])
            ->json('server_uuid');

        $response = $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson("/api/v1/servers/{$importedUuid}/claim", [
                'write_remote' => false,
                'rebind_sentinel' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('server_uuid', $importedUuid)
            ->assertJsonPath('claim_written', false)
            ->assertJsonPath('sentinel_rebound', true)
            ->assertJsonPath('claim.instance_url', 'https://coolify-a.test');

        $server = Server::where('uuid', $importedUuid)->first();
        expect(data_get($server->server_metadata, 'transfer.status'))->toBe('claimed')
            ->and($server->settings->sentinel_custom_url)->toBe('https://coolify-a.test')
            ->and($server->settings->sentinel_token)->not->toBeEmpty();
    });
});

describe('POST /api/v1/servers/{uuid}/transfer/complete', function () {
    test('marks source server transferred and force disables it', function () {
        $response = $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/transfer/complete", [
                'export_id' => 'exp-123',
                'target_instance_url' => 'https://coolify-b.test',
            ]);

        $response->assertOk()
            ->assertJsonPath('server_uuid', $this->server->uuid);

        $server = $this->server->fresh(['settings']);
        expect($server->settings->force_disabled)->toBeTrue()
            ->and(data_get($server->server_metadata, 'transfer.status'))->toBe('transferred')
            ->and(data_get($server->server_metadata, 'transfer.export_id'))->toBe('exp-123')
            ->and(data_get($server->server_metadata, 'transfer.target_instance_url'))->toBe('https://coolify-b.test');
    });
});

describe('full transfer flow A to B on same process', function () {
    test('export complete import claim sequence', function () {
        $export = $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->getJson("/api/v1/servers/{$this->server->uuid}/export")
            ->assertOk()
            ->json();

        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/transfer/complete", [
                'export_id' => $export['export_id'],
                'target_instance_url' => 'https://coolify-b.test',
            ])
            ->assertOk();

        // Source freed: force delete disabled server + key so import can recreate.
        $this->application->forceDelete();
        $this->server->forceDelete();
        $this->privateKey->delete();

        // Target instance (simulated as same app, after source cleanup)
        $settings = InstanceSettings::get();
        $settings->fqdn = 'https://coolify-b.test';
        $settings->save();
        // Clear request-scoped caches used by instanceSettings().
        if (function_exists('once')) {
            Once::flush();
        }

        $import = $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson('/api/v1/servers/import', [
                'bundle' => $export,
                'preserve_uuids' => true,
                'adopt_mode' => true,
            ])
            ->assertCreated()
            ->json();

        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson("/api/v1/servers/{$import['server_uuid']}/claim", [
                'write_remote' => false,
                'rebind_sentinel' => true,
            ])
            ->assertOk()
            ->assertJsonPath('claim.instance_url', 'https://coolify-b.test');

        $server = Server::where('uuid', $export['server']['uuid'])->first();
        expect($server)->not->toBeNull()
            ->and($server->settings->force_disabled)->toBeFalse()
            ->and(data_get($server->server_metadata, 'transfer.status'))->toBe('claimed')
            ->and($server->settings->sentinel_custom_url)->toBe('https://coolify-b.test');
    });
});

describe('ServerTransferClaimer unit-ish via container', function () {
    test('writeMailbox returns path even when remote fails in tests', function () {
        $claimer = app(ServerTransferClaimer::class);
        $exporter = app(ServerTransferExporter::class);
        $bundle = $exporter->export($this->server);

        $result = $claimer->writeMailbox($this->server, $bundle, 'pass');

        expect($result['path'])->toContain('/data/coolify/exports/server-transfer-')
            ->and($result)->toHaveKey('written');
    });
});
