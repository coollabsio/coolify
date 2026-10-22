<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\MigrateServerToInstanceJob;
use App\Jobs\ValidateAndInstallServerJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\ServerTransfer\ServerTransferExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A claim writes the owner marker on the host over SSH.
    Process::fake();
    // An import validates the claimed server over SSH; the sync test queue would run it.
    Queue::fake([ValidateAndInstallServerJob::class]);
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
    $this->importToken = $this->user->createToken('transfer-import', ['read', 'write', 'deploy'])->plainTextToken;

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

test('server transfer API is available in production', function () {
    config(['app.env' => 'production']);

    $this->withHeaders(transferHeaders($this->sensitiveToken))
        ->getJson("/api/v1/servers/{$this->server->uuid}/transfer")
        ->assertOk()
        ->assertJsonPath('server_uuid', $this->server->uuid);
});

function transferExportForApiTest(Server $server): array
{
    return app(ServerTransferExporter::class)->export($server);
}

function transferHeaders(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ];
}

test('the manual export endpoints no longer exist', function (string $path) {
    $this->withHeaders(transferHeaders($this->sensitiveToken))
        ->postJson("/api/v1/servers/{$this->server->uuid}{$path}", ['passphrase' => 'correct horse battery'])
        ->assertNotFound();
})->with(['/export', '/export/mailbox']);

describe('POST /api/v1/servers/import', function () {
    test('dry run does not create resources', function () {
        $export = transferExportForApiTest($this->server);

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
        $export = transferExportForApiTest($this->server);

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

    test('keeps whitespace and empty strings of the bundle values', function () {
        $this->application->forceFill(['dockerfile' => "FROM nginx:alpine\nEXPOSE 80\n", 'build_pack' => 'dockerfile'])->saveQuietly();
        LocalFileVolume::withoutEvents(fn () => LocalFileVolume::forceCreate([
            'uuid' => new_public_id(), 'fs_path' => '/data/coolify/applications/app.yml', 'mount_path' => '/etc/app.yml',
            'content' => "  indented: true\nlast: line\n", 'resource_type' => Application::class, 'resource_id' => $this->application->id,
        ]));
        SharedEnvironmentVariable::create(['key' => 'EMPTY_ON_SERVER', 'value' => '', 'type' => 'server', 'server_id' => $this->server->id, 'team_id' => $this->team->id]);
        $export = transferExportForApiTest($this->server);
        $appUuid = $this->application->uuid;
        $this->application->forceDelete();
        $this->server->forceDelete();
        $this->privateKey->delete();

        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson('/api/v1/servers/import', ['bundle' => $export])
            ->assertCreated();

        $imported = Application::where('uuid', $appUuid)->firstOrFail();
        expect($imported->dockerfile)->toBe("FROM nginx:alpine\nEXPOSE 80\n")
            ->and($imported->fileStorages()->value('content'))->toBe("  indented: true\nlast: line\n")
            ->and(SharedEnvironmentVariable::where('key', 'EMPTY_ON_SERVER')->first()?->value)->toBe('');
    });

    test('rejects import when ip still exists', function () {
        $export = transferExportForApiTest($this->server);

        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson('/api/v1/servers/import', ['bundle' => $export])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['server.uuid']);
    });

    test('does not reveal the server of another team when the ip exists', function () {
        $export = transferExportForApiTest($this->server);
        $this->application->forceDelete();
        $this->server->forceDelete();
        $otherServer = Server::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'private_key_id' => $this->privateKey->id,
            'ip' => '10.66.0.20',
        ]);

        $response = $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson('/api/v1/servers/import', ['bundle' => $export])
            ->assertStatus(422);

        expect($response->getContent())->toContain('already exists')
            ->not->toContain($otherServer->uuid);
    });

    test('rejects a bundle from a newer Coolify version', function () {
        $export = transferExportForApiTest($this->server);
        $export['coolify_version'] = '99.0.0';
        $this->application->forceDelete();
        $this->server->forceDelete();

        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson('/api/v1/servers/import', ['bundle' => $export])
            ->assertStatus(422)
            ->assertJsonPath('errors.bundle.0', fn ($message) => str_contains($message, 'Upgrade this instance'));

        expect(Server::where('uuid', $export['server']['uuid'])->exists())->toBeFalse();
    });

    test('adopt mode requires the original uuids', function () {
        $export = transferExportForApiTest($this->server);
        $this->application->forceDelete();
        $this->server->forceDelete();

        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson('/api/v1/servers/import', ['bundle' => $export, 'preserve_uuids' => false, 'adopt_mode' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['preserve_uuids']);
    });

    test('rejects import when the destination team is at its cloud server limit', function () {
        config()->set('constants.coolify.self_hosted', false);
        $this->team->update(['custom_server_limit' => 1]);

        $export = transferExportForApiTest($this->server);

        $this->server->forceDelete();
        Server::factory()->create([
            'team_id' => $this->team->id,
            'private_key_id' => $this->privateKey->id,
            'ip' => '10.66.0.21',
        ]);

        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson('/api/v1/servers/import', ['bundle' => $export])
            ->assertStatus(422)
            ->assertJsonPath('errors.server.0', 'Server limit reached for your subscription.');

        expect(Server::where('team_id', $this->team->id)->count())->toBe(1);
    });
});

describe('POST /api/v1/servers/import claim', function () {
    test('import takes management of the server and rebinds Sentinel', function () {
        $export = transferExportForApiTest($this->server);

        $this->application->forceDelete();
        $this->server->forceDelete();
        $this->privateKey->delete();

        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson('/api/v1/servers/import', ['bundle' => $export])
            ->assertCreated()
            ->assertJsonPath('claimed', true)
            ->assertJsonPath('claim.claim.instance_url', 'https://coolify-a.test');

        $server = Server::where('uuid', $export['server']['uuid'])->first();
        expect(data_get($server->server_metadata, 'transfer.status'))->toBe('claimed')
            ->and((bool) $server->settings->is_sentinel_enabled)->toBeTrue()
            ->and($server->settings->sentinel_custom_url)->toBe('https://coolify-a.test')
            ->and($server->settings->sentinel_token)->not->toBeEmpty();
    });

    test('claim endpoint is removed', function () {
        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/claim")
            ->assertNotFound();
    });

    test('import rejects the removed claim options', function () {
        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson('/api/v1/servers/import', ['bundle' => [], 'write_remote' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['write_remote']);
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
    test('export complete import sequence', function () {
        $export = transferExportForApiTest($this->server);

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

        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson('/api/v1/servers/import', [
                'bundle' => $export,
                'preserve_uuids' => true,
                'adopt_mode' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('claimed', true);

        $server = Server::where('uuid', $export['server']['uuid'])->first();
        expect($server)->not->toBeNull()
            ->and($server->settings->force_disabled)->toBeFalse()
            ->and(data_get($server->server_metadata, 'transfer.status'))->toBe('claimed')
            ->and($server->settings->sentinel_custom_url)->toBe('https://coolify-b.test');
    });
});

describe('POST /api/v1/servers/{uuid}/migrate', function () {
    test('starts the transfer in a queued job', function () {
        Queue::fake();
        Http::fake(['*/api/v1/servers/import/check*' => Http::response(['message' => 'ok', 'checks' => []])]);

        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/migrate", [
                'target_url' => 'https://8.8.8.8',
                'target_token' => 'target-token',
            ])
            ->assertStatus(202)
            ->assertJsonPath('migration.status', 'running');

        Queue::assertPushed(MigrateServerToInstanceJob::class, fn ($job) => $job->server->is($this->server) && $job->targetToken === 'target-token');
        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->getJson("/api/v1/servers/{$this->server->uuid}/transfer")
            ->assertOk()
            ->assertJsonPath('migration.status', 'running')
            ->assertJsonPath('migration.target_url', 'https://8.8.8.8');
    });

    test('rejects a target without https', function () {
        Queue::fake();

        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/migrate", [
                'target_url' => 'http://8.8.8.8',
                'target_token' => 'target-token',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'https'));

        Queue::assertNothingPushed();
    });

    test('rejects a transfer while a deployment runs on the server', function () {
        Queue::fake();
        ApplicationDeploymentQueue::create([
            'application_id' => (string) $this->application->id,
            'deployment_uuid' => new_public_id(),
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);

        $this->withHeaders(transferHeaders($this->sensitiveToken))
            ->postJson("/api/v1/servers/{$this->server->uuid}/migrate", [
                'target_url' => 'https://8.8.8.8',
                'target_token' => 'target-token',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'deployment'));

        Queue::assertNothingPushed();
    });
});

describe('GET /api/v1/servers/import/check', function () {
    test('accepts a server that this instance can import and reach', function () {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);

        $this->withHeaders(transferHeaders($this->importToken))
            ->getJson("/api/v1/servers/import/check?server_uuid=new-server-uuid&ip=127.0.0.1&port={$port}")
            ->assertOk()
            ->assertJsonPath('checks', fn ($checks) => collect($checks)->every(fn ($check) => $check['ok'] === true)
                && collect($checks)->pluck('label')->contains("the target can reach the SSH port of the server (127.0.0.1:{$port})"));

        fclose($listener);
    });

    test('rejects a server whose SSH port this instance cannot reach', function () {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);

        $this->withHeaders(transferHeaders($this->importToken))
            ->getJson("/api/v1/servers/import/check?server_uuid=new-server-uuid&ip=127.0.0.1&port={$port}")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'cannot reach the server at 127.0.0.1'));
    });

    test('rejects a target that is not an IP address or hostname before it opens a socket', function (string $ip) {
        $this->withHeaders(transferHeaders($this->importToken))
            ->getJson('/api/v1/servers/import/check?server_uuid=new-server-uuid&ip='.urlencode($ip))
            ->assertStatus(422)
            ->assertJsonValidationErrors('ip')
            ->assertJsonMissingPath('checks');
    })->with(['tcp://127.0.0.1', 'unix:///var/run/docker.sock', 'ssl://example.com']);

    test('rejects another transfer bundle version', function () {
        $this->withHeaders(transferHeaders($this->importToken))
            ->getJson('/api/v1/servers/import/check?server_uuid=new-server-uuid&ip=127.0.0.1&schema_version=999')
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'Upgrade both instances'));
    });

    test('lists every check with its result when a server already exists on this instance', function () {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        $this->server->forceFill(['ip' => '127.0.0.1'])->save();

        $checks = $this->withHeaders(transferHeaders($this->importToken))
            ->getJson("/api/v1/servers/import/check?server_uuid=new-server-uuid&ip=127.0.0.1&port={$port}")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'already exists on this instance'))
            ->json('checks');

        expect(collect($checks)->pluck('ok', 'label')->all())->toMatchArray([
            'the transfer bundle version is supported' => true,
            'no server with the same UUID exists' => true,
            'no server with the same IP exists' => false,
            "the target can reach the SSH port of the server (127.0.0.1:{$port})" => true,
        ]);
        fclose($listener);
    });

    test('rejects a token without the write permission', function () {
        $this->withHeaders(transferHeaders($this->readToken))
            ->getJson('/api/v1/servers/import/check?server_uuid=new-server-uuid&ip=10.66.0.99')
            ->assertForbidden();
    });

    test('rejects a token without the deploy permission that the import needs', function () {
        $this->withHeaders(transferHeaders($this->writeToken))
            ->getJson('/api/v1/servers/import/check?server_uuid=new-server-uuid&ip=10.66.0.99')
            ->assertForbidden();
    });

    test('rejects an invalid token', function () {
        $this->withHeaders(transferHeaders('1|not-a-real-token'))
            ->getJson('/api/v1/servers/import/check?server_uuid=new-server-uuid&ip=10.66.0.99')
            ->assertUnauthorized();
    });
});

test('the import check lists the system-wide git sources that this instance does not have', function () {
    $present = GithubApp::create(['name' => 'present', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com', 'team_id' => 0, 'is_system_wide' => true]);
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);

    $this->withHeaders(transferHeaders($this->importToken))
        ->getJson("/api/v1/servers/import/check?server_uuid=new-server-uuid&ip=127.0.0.1&port={$port}&system_github_apps[]={$present->uuid}&system_github_apps[]=missing-uuid&system_gitlab_apps[]=other-missing")
        ->assertOk()
        ->assertJsonPath('missing_system_sources.github', ['missing-uuid'])
        ->assertJsonPath('missing_system_sources.gitlab', ['other-missing']);

    fclose($listener);
});
