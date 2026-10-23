<?php

use App\Jobs\ValidateAndInstallServerJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\ManagedDnsRecord;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ResourceLink;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneSqlite;
use App\Models\SwarmDocker;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Services\ServerTransfer\ServerTransferBundle;
use App\Services\ServerTransfer\ServerTransferClaimer;
use App\Services\ServerTransfer\ServerTransferExporter;
use App\Services\ServerTransfer\ServerTransferImporter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // A claim writes the owner marker on the host over SSH.
    Process::fake();
    Storage::fake('ssh-keys');
    // An import validates the claimed server over SSH; the sync test queue would run it.
    Queue::fake([ValidateAndInstallServerJob::class]);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true, 'fqdn' => 'https://coolify-a.test']);

    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id, 'name' => 'transfer-key']);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'ip' => '10.55.0.10',
        'name' => 'source-server',
        'description' => 'Server to export',
        'port' => 22,
        'user' => 'root',
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->destination->update(['name' => 'coolify', 'network' => 'coolify']);

    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Transfer Project']);
    $this->environment = $this->project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $this->project->id, 'name' => 'production']);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'my-app',
        'git_repository' => 'https://github.com/example/app',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'fqdn' => 'https://app.example.com',
        'status' => 'running:healthy',
    ]);

    EnvironmentVariable::withoutEvents(function () {
        $env = new EnvironmentVariable;
        $env->forceFill([
            'key' => 'APP_SECRET',
            'value' => 'super-secret-value',
            'resourceable_type' => Application::class,
            'resourceable_id' => $this->application->id,
            'is_preview' => false,
            'is_runtime' => true,
            'is_buildtime' => true,
        ]);
        $env->uuid = new_public_id();
        $env->save();
    });

    LocalPersistentVolume::create([
        'name' => $this->application->uuid.'-data',
        'mount_path' => '/app/data',
        'host_path' => null,
        'resource_type' => $this->application->getMorphClass(),
        'resource_id' => $this->application->id,
    ]);

    ScheduledTask::create([
        'name' => 'nightly',
        'command' => 'php artisan schedule:run',
        'frequency' => '0 0 * * *',
        'application_id' => $this->application->id,
        'team_id' => $this->team->id,
        'enabled' => true,
    ]);

    StandalonePostgresql::withoutEvents(function () {
        $database = new StandalonePostgresql;
        $database->forceFill([
            'name' => 'app-db',
            'postgres_user' => 'postgres',
            'postgres_password' => 'db-password-secret',
            'postgres_db' => 'app',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'status' => 'running:healthy',
        ]);
        $database->uuid = new_public_id();
        $database->save();
        $this->database = $database;
    });

    LocalPersistentVolume::create([
        'name' => 'postgres-data-'.$this->database->uuid,
        'mount_path' => '/var/lib/postgresql/data',
        'resource_type' => $this->database->getMorphClass(),
        'resource_id' => $this->database->id,
    ]);

    SharedEnvironmentVariable::create([
        'key' => 'SHARED_API_KEY',
        'value' => 'shared-secret',
        'type' => 'server',
        'server_id' => $this->server->id,
        'team_id' => $this->team->id,
        'is_literal' => true,
    ]);

    $this->appTag = Tag::create([
        'name' => 'transfer-demo',
        'team_id' => $this->team->id,
    ]);
    $this->application->tags()->attach($this->appTag->id);

    $this->dbTag = Tag::create([
        'name' => 'critical',
        'team_id' => $this->team->id,
    ]);
    $this->database->tags()->attach($this->dbTag->id);

    $this->backup = ScheduledDatabaseBackup::create([
        'uuid' => new_public_id(),
        'team_id' => $this->team->id,
        'enabled' => true,
        'save_s3' => false,
        'frequency' => '0 2 * * *',
        'databases_to_backup' => 'app',
        'database_type' => $this->database->getMorphClass(),
        'database_id' => $this->database->id,
    ]);

    $this->service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'server_id' => $this->server->id,
        'name' => 'demo-service',
        'docker_compose_raw' => "services:\n  whoami:\n    image: traefik/whoami\n",
    ]);

    $this->serviceTask = ScheduledTask::create([
        'name' => 'service-ping',
        'command' => 'echo ping',
        'frequency' => '*/15 * * * *',
        'service_id' => $this->service->id,
        'team_id' => $this->team->id,
        'enabled' => true,
    ]);

    $this->exporter = app(ServerTransferExporter::class);
    $this->importer = app(ServerTransferImporter::class);
});

test('export includes server applications databases and secrets in plaintext', function () {
    $bundle = $this->exporter->export($this->server);

    expect($bundle['schema_version'])->toBe(ServerTransferBundle::SCHEMA_VERSION)
        ->and($bundle['server']['uuid'])->toBe($this->server->uuid)
        ->and($bundle['server']['ip'])->toBe('10.55.0.10')
        ->and($bundle['private_key']['private_key'])->toContain('BEGIN OPENSSH PRIVATE KEY')
        ->and($bundle['private_key']['fingerprint'])->toBe($this->privateKey->fingerprint)
        ->and($bundle['destinations'])->not->toBeEmpty()
        ->and($bundle['projects'])->toHaveCount(1)
        ->and($bundle['shared_environment_variables']['server'])->toHaveCount(1);

    $environment = $bundle['projects'][0]['environments'][0];
    expect($environment['applications'])->toHaveCount(1)
        ->and($environment['databases'])->toHaveCount(1)
        ->and($environment['services'])->toHaveCount(1);

    $app = $environment['applications'][0];
    $secret = collect($app['environment_variables'])->firstWhere('key', 'APP_SECRET');
    expect($app['uuid'])->toBe($this->application->uuid)
        ->and($app['attributes']['name'])->toBe('my-app')
        ->and($secret)->not->toBeNull()
        ->and($secret['value'])->toBe('super-secret-value')
        ->and($app['persistent_storages'])->toHaveCount(1)
        ->and($app['scheduled_tasks'])->toHaveCount(1)
        ->and($app['tags'])->toHaveCount(1)
        ->and($app['tags'][0]['name'])->toBe('transfer-demo');

    $db = $environment['databases'][0];
    expect($db['type'])->toBe('StandalonePostgresql')
        ->and($db['attributes']['postgres_password'])->toBe('db-password-secret')
        ->and($db['tags'])->toHaveCount(1)
        ->and($db['tags'][0]['name'])->toBe('critical')
        ->and($db['scheduled_backups'])->toHaveCount(1)
        ->and($db['scheduled_backups'][0]['frequency'])->toBe('0 2 * * *');

    $service = $environment['services'][0];
    expect($service['uuid'])->toBe($this->service->uuid)
        ->and($service['scheduled_tasks'])->toHaveCount(1)
        ->and($service['scheduled_tasks'][0]['name'])->toBe('service-ping');
});

test('export refuses localhost coolify host', function () {
    $localhost = Server::factory()->create([
        'id' => 0,
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'ip' => 'host.docker.internal',
    ]);

    $this->exporter->export($localhost);
})->throws(RuntimeException::class);

test('dry run import reports counts without creating server', function () {
    $bundle = $this->exporter->export($this->server);
    $before = Server::count();

    $result = $this->importer->import($bundle, teamId: $this->team->id, dryRun: true);

    expect($result['dry_run'])->toBeTrue()
        ->and($result['created']['applications'])->toBe(1)
        ->and($result['created']['databases'])->toBe(1)
        ->and(Server::count())->toBe($before);
});

test('round trip import preserves uuids secrets and related resources after source removal', function () {
    $bundle = $this->exporter->export($this->server);
    $originalServerUuid = $this->server->uuid;
    $originalAppUuid = $this->application->uuid;
    $originalDbUuid = $this->database->uuid;
    $originalDestUuid = $this->destination->uuid;
    $originalServiceUuid = $this->service->uuid;
    $originalBackupUuid = $this->backup->uuid;
    $originalServiceTaskUuid = $this->serviceTask->uuid;
    $keyMaterial = $this->privateKey->private_key;

    // Simulate handoff: source instance no longer owns the IP / records.
    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();
    Tag::query()->delete();
    ScheduledDatabaseBackup::query()->delete();
    ScheduledTask::query()->delete();
    // Same fingerprint cannot exist twice; remove source key so target re-creates it.
    $this->privateKey->delete();

    $result = $this->importer->import($bundle, teamId: $this->team->id, dryRun: false, preserveUuids: true, adoptMode: true);

    expect($result['dry_run'])->toBeFalse()
        ->and($result['server_uuid'])->toBe($originalServerUuid)
        ->and($result['created']['applications'])->toBe(1)
        ->and($result['created']['databases'])->toBe(1)
        ->and($result['created']['services'])->toBe(1);

    $server = Server::where('uuid', $originalServerUuid)->first();
    expect($server)->not->toBeNull()
        ->and($server->ip)->toBe('10.55.0.10')
        ->and($server->name)->toBe('source-server')
        ->and($server->privateKey->private_key)->toBe($keyMaterial)
        ->and(data_get($server->server_metadata, 'transfer.status'))->toBe('claimed')
        ->and(data_get($server->server_metadata, 'transfer.adopt_mode'))->toBeTrue();

    $destination = StandaloneDocker::where('server_id', $server->id)->where('uuid', $originalDestUuid)->first();
    expect($destination)->not->toBeNull();

    $app = Application::where('uuid', $originalAppUuid)->first();
    expect($app)->not->toBeNull()
        ->and($app->name)->toBe('my-app')
        ->and($app->fqdn)->toBe('https://app.example.com')
        ->and($app->environment_variables()->where('key', 'APP_SECRET')->first()?->value)->toBe('super-secret-value')
        ->and($app->persistentStorages)->toHaveCount(1)
        ->and($app->scheduled_tasks)->toHaveCount(1)
        ->and($app->tags()->pluck('name')->all())->toContain('transfer-demo');

    $db = StandalonePostgresql::where('uuid', $originalDbUuid)->first();
    expect($db)->not->toBeNull()
        ->and($db->postgres_password)->toBe('db-password-secret')
        ->and($db->persistentStorages)->not->toBeEmpty()
        ->and($db->tags()->pluck('name')->all())->toContain('critical');

    $backup = ScheduledDatabaseBackup::where('uuid', $originalBackupUuid)->first();
    expect($backup)->not->toBeNull()
        ->and($backup->frequency)->toBe('0 2 * * *')
        ->and((bool) $backup->enabled)->toBeTrue()
        ->and($backup->database_id)->toBe($db->id);

    $service = Service::where('uuid', $originalServiceUuid)->first();
    expect($service)->not->toBeNull()
        ->and($service->name)->toBe('demo-service');

    $serviceTask = ScheduledTask::where('uuid', $originalServiceTaskUuid)->first();
    expect($serviceTask)->not->toBeNull()
        ->and($serviceTask->service_id)->toBe($service->id)
        ->and($serviceTask->name)->toBe('service-ping')
        ->and($serviceTask->command)->toBe('echo ping');

    $shared = SharedEnvironmentVariable::query()
        ->where('server_id', $server->id)
        ->where('key', 'SHARED_API_KEY')
        ->first();
    expect($shared)->not->toBeNull()
        ->and($shared->value)->toBe('shared-secret');
});

test('an imported application and service keep their compose parser version and their variables', function () {
    $this->application->forceFill(['build_pack' => 'nixpacks', 'compose_parsing_version' => '3'])->saveQuietly();
    EnvironmentVariable::updateOrCreate(['key' => 'NIXPACKS_NODE_VERSION', 'resourceable_type' => $this->application->getMorphClass(), 'resourceable_id' => $this->application->id], ['value' => '18']);
    $this->service->forceFill(['compose_parsing_version' => '3'])->saveQuietly();
    $bundle = $this->exporter->export($this->server);
    $applicationUuid = $this->application->uuid;
    $serviceUuid = $this->service->uuid;
    removeTransferSourceResources($this);

    $this->importer->import($bundle, teamId: Team::factory()->create()->id);

    $application = Application::where('uuid', $applicationUuid)->firstOrFail();
    expect($application->compose_parsing_version)->toBe('3')
        ->and(Service::where('uuid', $serviceUuid)->value('compose_parsing_version'))->toBe('3')
        ->and($application->environment_variables()->where('key', 'NIXPACKS_NODE_VERSION')->pluck('value')->all())->toBe(['18']);
});

test('a static application keeps the port that the user set after turning on static', function () {
    $this->application->settings->forceFill(['is_static' => true])->save();
    $this->application->refresh()->forceFill(['ports_exposes' => '8080'])->saveQuietly();
    $bundle = $this->exporter->export($this->server);
    $applicationUuid = $this->application->uuid;
    removeTransferSourceResources($this);

    $this->importer->import($bundle, teamId: Team::factory()->create()->id);

    $application = Application::where('uuid', $applicationUuid)->firstOrFail();
    expect($application->ports_exposes)->toBe('8080')
        ->and($application->settings->is_static)->toBeTrue();
});

test('a swarm server gets only the destinations of the bundle', function () {
    $swarm = (new SwarmDocker)->forceFill(['uuid' => new_public_id(), 'name' => 'swarm', 'network' => 'swarm-overlay', 'server_id' => $this->server->id]);
    $swarm->saveQuietly();
    foreach ([$this->application, $this->database, $this->service] as $resource) {
        $resource->forceFill(['destination_type' => $swarm->getMorphClass(), 'destination_id' => $swarm->id])->saveQuietly();
    }
    $this->destination->delete();
    $this->server->settings->forceFill(['is_swarm_manager' => true])->saveQuietly();
    $bundle = $this->exporter->export($this->server->fresh());
    $serverUuid = $this->server->uuid;
    removeTransferSourceResources($this);

    $this->importer->import($bundle, teamId: Team::factory()->create()->id);

    $server = Server::where('uuid', $serverUuid)->firstOrFail();
    expect($server->standaloneDockers()->count())->toBe(0)
        ->and($server->swarmDockers()->pluck('network')->all())->toBe(['swarm-overlay']);
});

test('variables with a legacy key that new variables cannot use move unchanged', function () {
    $variable = EnvironmentVariable::create(['key' => 'LEGACY_KEY', 'value' => 'legacy', 'resourceable_type' => $this->application->getMorphClass(), 'resourceable_id' => $this->application->id]);
    DB::table('environment_variables')->where('id', $variable->id)->update(['key' => 'legacy-key']);
    $shared = SharedEnvironmentVariable::create(['type' => 'server', 'server_id' => $this->server->id, 'team_id' => $this->team->id, 'key' => 'LEGACY_SHARED', 'value' => 'shared']);
    DB::table('shared_environment_variables')->where('id', $shared->id)->update(['key' => 'legacy-shared']);
    $bundle = $this->exporter->export($this->server);
    $applicationUuid = $this->application->uuid;
    removeTransferSourceResources($this);

    $this->importer->import($bundle, teamId: Team::factory()->create()->id);

    expect(Application::where('uuid', $applicationUuid)->firstOrFail()->environment_variables()->where('key', 'legacy-key')->value('value'))->toBe('legacy')
        ->and(SharedEnvironmentVariable::where('key', 'legacy-shared')->value('value'))->toBe('shared');
});

test('import validates the claimed server in the background, but a dry run does not', function () {
    $bundle = $this->exporter->export($this->server);
    $serverUuid = $this->server->uuid;

    $this->importer->import($bundle, teamId: $this->team->id, dryRun: true);
    Queue::assertNotPushed(ValidateAndInstallServerJob::class);

    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();

    $result = $this->importer->import($bundle, teamId: $this->team->id);

    expect($result['claimed'])->toBeTrue();
    Queue::assertPushed(ValidateAndInstallServerJob::class, 1);
    Queue::assertPushed(ValidateAndInstallServerJob::class, fn (ValidateAndInstallServerJob $job) => $job->server->uuid === $serverUuid);
});

test('import fails when server ip already exists', function () {
    $bundle = $this->exporter->export($this->server);

    $this->importer->import($bundle, teamId: $this->team->id);
})->throws(ValidationException::class);

test('the Cloudflare tunnel token moves with the server by uuid and the raw token id of an old bundle is ignored', function () {
    $token = IntegrationToken::factory()->for($this->team)->create(['provider' => 'cloudflare', 'token' => 'cf-tunnel-token', 'capabilities' => ['dns', 'tunnel']]);
    $this->server->settings->forceFill(['cloudflare_tunnel_integration_token_id' => $token->id])->saveQuietly();

    $bundle = $this->exporter->export($this->server);
    expect(data_get($bundle, 'server.settings'))->not->toHaveKey('cloudflare_tunnel_integration_token_id')
        ->and(data_get($bundle, 'server.settings.cloudflare_tunnel_integration_token_uuid'))->toBe($token->uuid)
        ->and(data_get($bundle, 'integration_tokens.0.token'))->toBe('cf-tunnel-token')
        ->and(implode(' ', $bundle['warnings']))->not->toContain('Cloudflare API token');

    $bundle['server']['settings']['cloudflare_tunnel_integration_token_id'] = 999999;
    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();
    $token->delete();

    $result = $this->importer->import($bundle, teamId: $this->team->id);

    $imported = IntegrationToken::where('uuid', $token->uuid)->firstOrFail();
    expect(Server::where('uuid', $result['server_uuid'])->first()->settings->cloudflare_tunnel_integration_token_id)->toBe($imported->id)
        ->and($imported->team_id)->toBe($this->team->id)
        ->and($imported->token)->toBe('cf-tunnel-token');
});

test('the patch check frequency moves with the server and the last patch check result stays on the source', function () {
    $this->server->settings->forceFill([
        'patch_check_frequency' => 'daily',
        'patch_check_result' => ['osId' => 'ubuntu', 'package_manager' => 'apt', 'total_updates' => 0, 'updates' => []],
        'patch_check_error' => 'old error',
        'patch_checked_at' => now(),
        'is_auto_security_updates_enabled' => true,
        'auto_security_updates_time' => '04:30',
    ])->saveQuietly();

    $bundle = $this->exporter->export($this->server);
    expect(data_get($bundle, 'server.settings'))->not->toHaveKeys(['patch_check_result', 'patch_check_error', 'patch_checked_at']);

    $bundle['server']['settings']['patch_check_result'] = ['osId' => 'tampered'];
    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();

    $result = $this->importer->import($bundle, teamId: $this->team->id);

    $settings = Server::where('uuid', $result['server_uuid'])->first()->settings;
    expect($settings->patch_check_frequency)->toBe('daily')
        ->and($settings->is_auto_security_updates_enabled)->toBeTrue()
        ->and($settings->auto_security_updates_time)->toBe('04:30')
        ->and($settings->patch_check_result)->toBeNull()
        ->and($settings->patch_checked_at)->toBeNull();
});

test('a transfer back replaces the archive of the same team in the import transaction', function () {
    // The claim restarts Sentinel with its new token; this test is about the resource containers.
    Queue::fake();
    $bundle = $this->exporter->export($this->server);
    $bundle['server']['name'] = 'changed-on-the-other-instance';
    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://coolify-b.test');
    $archiveId = $this->server->id;

    expect(collect($this->importer->importChecks($this->server->uuid, $this->server->ip, $this->team->id))->every(fn ($check) => $check['ok']))->toBeTrue();

    $result = $this->importer->import($bundle, teamId: $this->team->id);

    $server = Server::where('uuid', $this->server->uuid)->firstOrFail();
    expect($result['claimed'])->toBeTrue()
        ->and($server->id)->not->toBe($archiveId)
        ->and($server->name)->toBe('changed-on-the-other-instance')
        ->and($server->isTransferredAway())->toBeFalse()
        ->and(Server::withTrashed()->find($archiveId))->toBeNull()
        ->and(Application::where('uuid', $this->application->uuid)->firstOrFail()->destination->server_id)->toBe($server->id);
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'docker rm') || str_contains($process->command, 'docker stop'));
});

test('the import check of a transfer back needs a free server slot, because the archive does not use one', function () {
    config()->set('constants.coolify.self_hosted', false);
    $this->team->update(['custom_server_limit' => 1]);
    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://coolify-b.test');
    Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->server->private_key_id, 'ip' => '203.0.113.77']);

    $limitCheck = collect($this->importer->importChecks($this->server->uuid, $this->server->ip, $this->team->id))->firstWhere('field', 'server');

    expect($limitCheck['ok'])->toBeFalse();
});

test('a failed transfer back keeps the archive', function () {
    $bundle = $this->exporter->export($this->server);
    $bundle['projects'][0]['environments'][0]['databases'][] = [
        'type' => 'NotARealDatabaseType', 'uuid' => 'broken-db-uuid', 'attributes' => ['name' => 'broken'], 'destination_uuid' => $this->destination->uuid,
    ];
    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://coolify-b.test');

    expect(fn () => $this->importer->import($bundle, teamId: $this->team->id))->toThrow(RuntimeException::class, 'Unsupported database type');

    expect($this->server->fresh()?->isTransferredAway())->toBeTrue()
        ->and(Application::where('uuid', $this->application->uuid)->exists())->toBeTrue();
});

test('the archive of another team is not replaced', function () {
    $bundle = $this->exporter->export($this->server);
    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://coolify-b.test');
    $otherTeam = Team::factory()->create();

    expect(fn () => $this->importer->import($bundle, teamId: $otherTeam->id))
        ->toThrow(ValidationException::class, 'Another team of this instance has the archived copy of this server.');

    $uuidCheck = collect($this->importer->importChecks($this->server->uuid, '203.0.113.50', $otherTeam->id))->firstWhere('field', 'server.uuid');
    expect($uuidCheck['message'])->not->toContain($this->server->name);
});

test('import rolls back all created rows when a later resource fails', function () {
    $bundle = $this->exporter->export($this->server);
    $originalServerUuid = $this->server->uuid;

    // Force a failure after private keys / server would have been created.
    $bundle['projects'][0]['environments'][0]['databases'][] = [
        'type' => 'NotARealDatabaseType',
        'uuid' => 'broken-db-uuid',
        'attributes' => ['name' => 'broken'],
        'destination_uuid' => $this->destination->uuid,
    ];

    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();
    $this->privateKey->delete();

    expect(fn () => $this->importer->import(
        $bundle,
        teamId: $this->team->id,
        dryRun: false,
        preserveUuids: true,
        adoptMode: true,
    ))->toThrow(RuntimeException::class, 'Unsupported database type');

    expect(Server::where('uuid', $originalServerUuid)->exists())->toBeFalse()
        ->and(PrivateKey::where('uuid', data_get($bundle, 'private_key.uuid'))->exists())->toBeFalse()
        ->and(Application::where('uuid', data_get($bundle, 'projects.0.environments.0.applications.0.uuid'))->exists())->toBeFalse();
});

test('import fails on invalid schema', function () {
    $this->importer->import(['schema_version' => 1], teamId: $this->team->id);
})->throws(ValidationException::class);

test('import reuses existing private key fingerprint on same team', function () {
    $bundle = $this->exporter->export($this->server);
    $originalKeyId = $this->privateKey->id;

    // Free the IP without deleting the key.
    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();

    $result = $this->importer->import($bundle, teamId: $this->team->id);

    $server = Server::where('uuid', $result['server_uuid'])->first();
    expect($server->private_key_id)->toBe($originalKeyId);
});

test('import into another team creates its own key when the same key exists in a different team', function () {
    $bundle = $this->exporter->export($this->server);
    $sourceKeyId = $this->privateKey->id;
    $targetTeam = Team::factory()->create();

    // Free the IP without deleting the source team's key.
    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();

    $result = $this->importer->import($bundle, teamId: $targetTeam->id);

    $server = Server::where('uuid', $result['server_uuid'])->first();
    expect($server->team_id)->toBe($targetTeam->id)
        ->and($server->private_key_id)->not->toBe($sourceKeyId)
        ->and($server->privateKey->team_id)->toBe($targetTeam->id)
        ->and($server->privateKey->fingerprint)->toBe($this->privateKey->fingerprint)
        ->and(PrivateKey::whereKey($sourceKeyId)->where('team_id', $this->team->id)->exists())->toBeTrue();
});

test('system-wide github apps are not exported and re-link on import by uuid', function () {
    $systemWide = new GithubApp;
    $systemWide->forceFill([
        'name' => 'System Public GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => true,
        'is_system_wide' => true,
        'team_id' => $this->team->id,
    ]);
    $systemWide->uuid = 'system-github-public';
    $systemWide->save();

    $teamApp = new GithubApp;
    $teamApp->forceFill([
        'name' => 'Team GitHub App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
        'is_system_wide' => false,
        'team_id' => $this->team->id,
        'app_id' => 12345,
        'installation_id' => 67890,
        'client_id' => 'Iv1.test',
        'client_secret' => 'secret',
        'webhook_secret' => 'hook',
    ]);
    $teamApp->uuid = 'team-github-app';
    $teamApp->save();

    $this->application->source_type = GithubApp::class;
    $this->application->source_id = $systemWide->id;
    $this->application->save();

    $otherApp = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'team-source-app',
        'git_repository' => 'https://github.com/example/private',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'source_type' => GithubApp::class,
        'source_id' => $teamApp->id,
    ]);

    $bundle = $this->exporter->export($this->server);

    expect($bundle['github_apps'])->toHaveCount(1)
        ->and($bundle['github_apps'][0]['uuid'])->toBe('team-github-app')
        ->and(collect($bundle['warnings'])->implode(' '))->toContain('system-wide GitHub App');

    $appSources = collect($bundle['projects'][0]['environments'][0]['applications'])
        ->mapWithKeys(fn ($a) => [$a['attributes']['name'] => $a['source'] ?? null]);

    expect($appSources['my-app'])->toMatchArray(['type' => 'github_app', 'uuid' => 'system-github-public'])
        ->and($appSources['team-source-app'])->toMatchArray(['type' => 'github_app', 'uuid' => 'team-github-app']);

    // Free server IP / apps for import into same DB.
    $this->service->forceDelete();
    $otherApp->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();
    $this->privateKey->delete();
    GithubApp::where('uuid', 'team-github-app')->delete();
    // Simulate target instance already having the same system-wide app UUID.
    $systemWide->delete();

    $targetTeam = Team::factory()->create();
    $targetSystemWide = new GithubApp;
    $targetSystemWide->forceFill([
        'name' => 'System Public GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => true,
        'is_system_wide' => true,
        'team_id' => $targetTeam->id,
    ]);
    $targetSystemWide->uuid = 'system-github-public';
    $targetSystemWide->save();

    $result = $this->importer->import($bundle, teamId: $targetTeam->id, preserveUuids: true, adoptMode: true);

    expect($result['created']['github_apps'])->toBe(1);

    $importedSystem = Application::where('name', 'my-app')->first();
    $importedTeam = Application::where('name', 'team-source-app')->first();
    $importedGhTeam = GithubApp::where('uuid', 'team-github-app')->first();

    expect($importedSystem)->not->toBeNull()
        ->and($importedSystem->source_type)->toBe(GithubApp::class)
        ->and($importedSystem->source_id)->toBe($targetSystemWide->id)
        ->and($importedTeam->source_id)->toBe($importedGhTeam->id)
        ->and($importedGhTeam->is_system_wide)->toBeFalse()
        ->and(GithubApp::where('is_system_wide', true)->where('uuid', 'system-github-public')->count())->toBe(1);
});

test('service nested apps dbs volumes backups and db file storages round-trip', function () {
    $serviceApp = new ServiceApplication;
    $serviceApp->forceFill([
        'service_id' => $this->service->id,
        'name' => 'whoami',
        'human_name' => 'Whoami',
        'description' => 'demo nested app',
        'fqdn' => 'https://whoami.example.com',
        'ports' => '80:80',
        'exposes' => '80',
        'image' => 'traefik/whoami:latest',
        'exclude_from_status' => false,
        'required_fqdn' => true,
        'is_log_drain_enabled' => true,
        'is_gzip_enabled' => false,
        'is_stripprefix_enabled' => false,
        'is_force_https_enabled' => false,
        'status' => 'running:healthy',
    ]);
    $serviceApp->uuid = 'svc-app-whoami';
    $serviceApp->save();

    EnvironmentVariable::withoutEvents(function () use ($serviceApp) {
        $env = new EnvironmentVariable;
        $env->forceFill([
            'key' => 'WHOAMI_NAME',
            'value' => 'nested-secret',
            'resourceable_type' => ServiceApplication::class,
            'resourceable_id' => $serviceApp->id,
            'is_preview' => false,
            'is_runtime' => true,
            'is_buildtime' => false,
        ]);
        $env->uuid = new_public_id();
        $env->save();
    });

    LocalPersistentVolume::create([
        'name' => 'whoami-data',
        'mount_path' => '/data',
        'host_path' => null,
        'resource_type' => $serviceApp->getMorphClass(),
        'resource_id' => $serviceApp->id,
    ]);
    $serviceAppVolume = LocalPersistentVolume::where('name', 'whoami-data')->firstOrFail();

    LocalFileVolume::withoutEvents(function () use ($serviceApp) {
        $file = new LocalFileVolume;
        $file->forceFill([
            'fs_path' => './config.json',
            'mount_path' => '/app/config.json',
            'content' => '{"nested":true}',
            'is_directory' => false,
            'resource_type' => $serviceApp->getMorphClass(),
            'resource_id' => $serviceApp->id,
        ]);
        $file->uuid = 'svc-app-file-1';
        $file->save();
    });

    ScheduledVolumeBackup::create([
        'uuid' => 'svc-app-vol-backup',
        'backupable_type' => $serviceAppVolume->getMorphClass(),
        'backupable_id' => $serviceAppVolume->id,
        'team_id' => $this->team->id,
        'frequency' => '0 4 * * *',
        'enabled' => true,
        'save_s3' => false,
        'disable_local_backup' => false,
    ]);

    $serviceDb = new ServiceDatabase;
    $serviceDb->forceFill([
        'service_id' => $this->service->id,
        'name' => 'postgres',
        'human_name' => 'Nested PG',
        'image' => 'postgres:16',
        'ports' => '5432',
        'exposes' => '5432',
        'public_port' => 15432,
        'public_port_timeout' => 30,
        'is_public' => true,
        'custom_type' => 'postgresql',
        'status' => 'running:healthy',
    ]);
    $serviceDb->uuid = 'svc-db-postgres';
    $serviceDb->save();

    LocalPersistentVolume::create([
        'name' => 'svc-pg-data',
        'mount_path' => '/var/lib/postgresql/data',
        'resource_type' => $serviceDb->getMorphClass(),
        'resource_id' => $serviceDb->id,
    ]);

    LocalFileVolume::withoutEvents(function () use ($serviceDb) {
        $file = new LocalFileVolume;
        $file->forceFill([
            'fs_path' => './init.sql',
            'mount_path' => '/docker-entrypoint-initdb.d/init.sql',
            'content' => 'SELECT 1;',
            'is_directory' => false,
            'resource_type' => $serviceDb->getMorphClass(),
            'resource_id' => $serviceDb->id,
        ]);
        $file->uuid = 'svc-db-file-1';
        $file->save();
    });

    $serviceDbBackup = ScheduledDatabaseBackup::create([
        'uuid' => 'svc-db-backup-1',
        'team_id' => $this->team->id,
        'enabled' => true,
        'save_s3' => false,
        'frequency' => '0 3 * * *',
        'databases_to_backup' => 'postgres',
        'database_type' => $serviceDb->getMorphClass(),
        'database_id' => $serviceDb->id,
    ]);

    // Standalone database file storage
    LocalFileVolume::withoutEvents(function () {
        $file = new LocalFileVolume;
        $file->forceFill([
            'fs_path' => './pg-conf.d',
            'mount_path' => '/etc/postgresql/conf.d',
            'content' => null,
            'is_directory' => true,
            'resource_type' => $this->database->getMorphClass(),
            'resource_id' => $this->database->id,
        ]);
        $file->uuid = 'standalone-db-file-1';
        $file->save();
    });

    // Preview + preview volume
    $preview = new ApplicationPreview;
    $preview->forceFill([
        'application_id' => $this->application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/app/pull/42',
        'fqdn' => 'https://pr-42.app.example.com',
        'status' => 'running:healthy',
        'git_type' => 'github',
    ]);
    $preview->uuid = 'preview-pr-42';
    $preview->save();

    LocalPersistentVolume::create([
        'name' => 'preview-42-data',
        'mount_path' => '/app/data',
        'resource_type' => $preview->getMorphClass(),
        'resource_id' => $preview->id,
    ]);

    $bundle = $this->exporter->export($this->server);

    $envPayload = $bundle['projects'][0]['environments'][0];
    $servicePayload = $envPayload['services'][0];
    $nestedApp = collect($servicePayload['applications'])->firstWhere('uuid', 'svc-app-whoami');
    $nestedDb = collect($servicePayload['databases'])->firstWhere('uuid', 'svc-db-postgres');
    $appPayload = $envPayload['applications'][0];
    $dbPayload = $envPayload['databases'][0];

    expect($nestedApp)->not->toBeNull()
        ->and($nestedApp['ports'])->toBe('80:80')
        ->and($nestedApp['exposes'])->toBe('80')
        ->and($nestedApp['environment_variables'])->toHaveCount(1)
        ->and($nestedApp['environment_variables'][0]['value'])->toBe('nested-secret')
        ->and($nestedApp['persistent_storages'])->toHaveCount(1)
        ->and($nestedApp['file_storages'])->toHaveCount(1)
        ->and($nestedApp['file_storages'][0]['content'])->toBe('{"nested":true}')
        ->and($nestedDb)->not->toBeNull()
        ->and($nestedDb['public_port'])->toBe(15432)
        ->and($nestedDb['custom_type'])->toBe('postgresql')
        ->and($nestedDb['persistent_storages'])->toHaveCount(1)
        ->and($nestedDb['file_storages'])->toHaveCount(1)
        ->and($nestedDb['scheduled_backups'])->toHaveCount(1)
        ->and($nestedDb['scheduled_backups'][0]['uuid'])->toBe('svc-db-backup-1')
        ->and($dbPayload['file_storages'])->toHaveCount(1)
        ->and($dbPayload['file_storages'][0]['uuid'])->toBe('standalone-db-file-1')
        ->and($appPayload['previews'])->toHaveCount(1)
        ->and($appPayload['previews'][0]['uuid'])->toBe('preview-pr-42')
        ->and($appPayload['previews'][0]['persistent_storages'])->toHaveCount(1)
        ->and(collect($bundle['volume_backups'])->pluck('uuid')->all())->toContain('svc-app-vol-backup');

    $originalServerUuid = $this->server->uuid;
    $originalAppUuid = $this->application->uuid;
    $originalDbUuid = $this->database->uuid;
    $originalServiceUuid = $this->service->uuid;

    // Free server IP / UUIDs for re-import. Clear soft-deleted previews (unique FQDN)
    // without firing remote docker cleanup hooks.
    ApplicationPreview::withoutEvents(function () {
        ApplicationPreview::withTrashed()->get()->each->forceDelete();
    });
    ServiceApplication::withoutEvents(function () {
        ServiceApplication::withTrashed()->get()->each->forceDelete();
    });
    ServiceDatabase::withoutEvents(function () {
        ServiceDatabase::withTrashed()->get()->each->forceDelete();
    });
    DB::table('environment_variables')->delete();
    DB::table('local_file_volumes')->delete();
    DB::table('local_persistent_volumes')->delete();
    DB::table('scheduled_volume_backups')->delete();
    DB::table('scheduled_database_backups')->delete();
    DB::table('scheduled_tasks')->delete();
    DB::table('services')->delete();
    DB::table('applications')->delete();
    DB::table('standalone_postgresqls')->delete();
    DB::table('standalone_dockers')->delete();
    DB::table('servers')->delete();
    $this->privateKey->delete();
    Tag::query()->delete();

    $result = $this->importer->import($bundle, teamId: $this->team->id, preserveUuids: true, adoptMode: true);

    expect($result['created']['services'])->toBe(1)
        ->and($result['server_uuid'])->toBe($originalServerUuid);

    $importedService = Service::where('uuid', $originalServiceUuid)->first();
    expect($importedService)->not->toBeNull();

    $importedSvcApp = ServiceApplication::where('uuid', 'svc-app-whoami')->first();
    expect($importedSvcApp)->not->toBeNull()
        ->and($importedSvcApp->ports)->toBe('80:80')
        ->and($importedSvcApp->exposes)->toBe('80')
        ->and((bool) $importedSvcApp->is_gzip_enabled)->toBeFalse()
        ->and((bool) $importedSvcApp->is_stripprefix_enabled)->toBeFalse()
        ->and((bool) $importedSvcApp->is_force_https_enabled)->toBeFalse()
        ->and($importedSvcApp->environment_variables()->where('key', 'WHOAMI_NAME')->first()?->value)->toBe('nested-secret')
        ->and($importedSvcApp->persistentStorages)->toHaveCount(1)
        ->and($importedSvcApp->fileStorages)->toHaveCount(1)
        ->and($importedSvcApp->fileStorages->first()->content)->toBe('{"nested":true}');

    $importedSvcDb = ServiceDatabase::where('uuid', 'svc-db-postgres')->first();
    expect($importedSvcDb)->not->toBeNull()
        ->and($importedSvcDb->public_port)->toBe(15432)
        ->and($importedSvcDb->custom_type)->toBe('postgresql')
        ->and($importedSvcDb->persistentStorages)->toHaveCount(1)
        ->and($importedSvcDb->fileStorages)->toHaveCount(1)
        ->and($importedSvcDb->scheduledBackups)->toHaveCount(1)
        ->and($importedSvcDb->scheduledBackups->first()->uuid)->toBe('svc-db-backup-1');

    $importedStandaloneDb = StandalonePostgresql::where('uuid', $originalDbUuid)->first();
    expect($importedStandaloneDb)->not->toBeNull()
        ->and($importedStandaloneDb->fileStorages)->toHaveCount(1)
        ->and($importedStandaloneDb->fileStorages->first()->uuid)->toBe('standalone-db-file-1');

    $importedApp = Application::where('uuid', $originalAppUuid)->first();
    expect($importedApp)->not->toBeNull()
        ->and($importedApp->previews)->toHaveCount(1)
        ->and($importedApp->previews->first()->uuid)->toBe('preview-pr-42')
        ->and($importedApp->previews->first()->persistentStorages)->toHaveCount(1);

    $importedVolBackup = ScheduledVolumeBackup::where('uuid', 'svc-app-vol-backup')->first();
    expect($importedVolBackup)->not->toBeNull()
        ->and($importedVolBackup->frequency)->toBe('0 4 * * *')
        ->and((bool) $importedVolBackup->enabled)->toBeTrue();
});

test('export refuses servers with additional destinations', function () {
    $extraDestination = StandaloneDocker::create([
        'name' => 'extra-net',
        'network' => 'extra-net-block',
        'server_id' => $this->server->id,
    ]);

    $this->application->additional_networks()->attach($extraDestination->id, [
        'server_id' => $this->server->id,
        'status' => 'running:healthy',
    ]);

    expect(fn () => $this->exporter->export($this->server))
        ->toThrow(RuntimeException::class, 'additional destinations');
});

test('system-wide gitlab apps are not exported and re-link on import by uuid', function () {
    $systemWide = new GitlabApp;
    $systemWide->forceFill([
        'name' => 'System Public GitLab',
        'api_url' => 'https://gitlab.com/api/v4',
        'html_url' => 'https://gitlab.com',
        'is_public' => true,
        'is_system_wide' => true,
        'team_id' => $this->team->id,
    ]);
    $systemWide->uuid = 'system-gitlab-public';
    $systemWide->save();

    $teamApp = new GitlabApp;
    $teamApp->forceFill([
        'name' => 'Team GitLab App',
        'api_url' => 'https://gitlab.com/api/v4',
        'html_url' => 'https://gitlab.com',
        'is_public' => false,
        'is_system_wide' => false,
        'team_id' => $this->team->id,
        'app_id' => '123',
        'app_secret' => 'secret',
        'oauth_id' => 1,
        'client_id' => 'client',
        'client_secret' => 'csecret',
        'group_name' => 'team',
    ]);
    $teamApp->uuid = 'team-gitlab-app';
    $teamApp->save();

    $this->application->source_type = GitlabApp::class;
    $this->application->source_id = $systemWide->id;
    $this->application->save();

    Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'team-gl-source-app',
        'git_repository' => 'https://gitlab.com/example/private',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'source_type' => GitlabApp::class,
        'source_id' => $teamApp->id,
    ]);

    $bundle = $this->exporter->export($this->server);

    expect($bundle['gitlab_apps'])->toHaveCount(1)
        ->and($bundle['gitlab_apps'][0]['uuid'])->toBe('team-gitlab-app')
        ->and(collect($bundle['warnings'])->implode(' '))->toContain('system-wide GitLab App');

    $this->service->forceDelete();
    Application::query()->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();
    $this->privateKey->delete();
    GitlabApp::where('uuid', 'team-gitlab-app')->delete();
    $systemWide->delete();

    $targetTeam = Team::factory()->create();
    $targetSystemWide = new GitlabApp;
    $targetSystemWide->forceFill([
        'name' => 'System Public GitLab',
        'api_url' => 'https://gitlab.com/api/v4',
        'html_url' => 'https://gitlab.com',
        'is_public' => true,
        'is_system_wide' => true,
        'team_id' => $targetTeam->id,
    ]);
    $targetSystemWide->uuid = 'system-gitlab-public';
    $targetSystemWide->save();

    $result = $this->importer->import($bundle, teamId: $targetTeam->id, preserveUuids: true, adoptMode: true);

    expect($result['created']['gitlab_apps'])->toBe(1);

    $importedSystem = Application::where('name', 'my-app')->first();
    $importedTeam = Application::where('name', 'team-gl-source-app')->first();
    $importedGlTeam = GitlabApp::where('uuid', 'team-gitlab-app')->first();

    expect($importedSystem)->not->toBeNull()
        ->and($importedSystem->source_type)->toBe(GitlabApp::class)
        ->and($importedSystem->source_id)->toBe($targetSystemWide->id)
        ->and($importedTeam->source_id)->toBe($importedGlTeam->id)
        ->and($importedGlTeam->is_system_wide)->toBeFalse()
        ->and(GitlabApp::where('is_system_wide', true)->where('uuid', 'system-gitlab-public')->count())->toBe(1);
});

test('team gitea apps round-trip and the application re-links to the imported app', function () {
    $giteaApp = new GiteaApp;
    $giteaApp->forceFill([
        'name' => 'Team Gitea App',
        'api_url' => 'https://gitea.example.com/api/v1',
        'html_url' => 'https://gitea.example.com',
        'is_public' => false,
        'is_system_wide' => false,
        'team_id' => $this->team->id,
        'client_id' => 'gitea-client',
        'client_secret' => 'gitea-secret',
        'webhook_secret' => 'gitea-webhook-secret',
    ]);
    $giteaApp->uuid = 'team-gitea-app';
    $giteaApp->save();

    $this->application->source_type = GiteaApp::class;
    $this->application->source_id = $giteaApp->id;
    $this->application->save();

    $bundle = $this->exporter->export($this->server);

    expect($bundle['gitea_apps'])->toHaveCount(1)
        ->and($bundle['gitea_apps'][0]['client_secret'])->toBe('gitea-secret')
        ->and(collect($bundle['post_transfer_checklist'])->implode(' '))->toContain('{target_url}/webhooks/source/gitea/redirect');

    $this->service->forceDelete();
    Application::query()->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();
    $this->privateKey->delete();
    $giteaApp->delete();

    $targetTeam = Team::factory()->create();
    $result = $this->importer->import($bundle, teamId: $targetTeam->id, preserveUuids: true, adoptMode: true);

    $imported = GiteaApp::where('uuid', 'team-gitea-app')->first();
    $application = Application::where('name', 'my-app')->first();

    expect($result['created']['gitea_apps'])->toBe(1)
        ->and($imported->team_id)->toBe($targetTeam->id)
        ->and($imported->client_secret)->toBe('gitea-secret')
        ->and($imported->webhook_secret)->toBe('gitea-webhook-secret')
        ->and($application->source_type)->toBe(GiteaApp::class)
        ->and($application->source_id)->toBe($imported->id);
});

test('import rejects unsafe host file paths before saving storage', function () {
    $importFileStorages = new ReflectionMethod(ServerTransferImporter::class, 'importFileStorages');
    $storage = [
        'fs_path' => '/etc/../passwd',
        'mount_path' => '/app/passwd',
        'is_host_file' => true,
    ];

    expect(fn () => $importFileStorages->invoke($this->importer, [$storage], $this->application))
        ->toThrow(Exception::class);
    expect(LocalFileVolume::count())->toBe(0);
});

test('import rejects shell-like file ownership and mode metadata', function (string $field, string $value) {
    $importFileStorages = new ReflectionMethod(ServerTransferImporter::class, 'importFileStorages');
    $storage = [
        'fs_path' => './config.json',
        'mount_path' => '/app/config.json',
        $field => $value,
    ];

    expect(fn () => $importFileStorages->invoke($this->importer, [$storage], $this->application))
        ->toThrow(RuntimeException::class);
    expect(LocalFileVolume::count())->toBe(0);
})->with([
    'owner command' => ['chown', '0:0; id'],
    'mode command' => ['chmod', '600; id'],
    'option mode' => ['chmod', '--reference=/etc/passwd'],
]);

test('transfer keeps the legacy escaping flag and treats old exports as legacy', function () {
    $secret = $this->application->environment_variables()->where('key', 'APP_SECRET')->firstOrFail();
    $secret->uses_legacy_escaping = true;
    $secret->save();

    $bundle = $this->exporter->export($this->server);
    $exported = collect($bundle['projects'][0]['environments'][0]['applications'][0]['environment_variables'])
        ->firstWhere('key', 'APP_SECRET');

    $importEnvVars = new ReflectionMethod($this->importer, 'importEnvVars');
    $importEnvVars->invoke($this->importer, [
        ['key' => 'EXACT_VAR', 'value' => 'a', 'uses_legacy_escaping' => false],
        ['key' => 'OLD_EXPORT_VAR', 'value' => 'b'],
    ], $this->application, false);

    expect($exported['uses_legacy_escaping'])->toBeTrue()
        ->and($this->application->environment_variables()->where('key', 'EXACT_VAR')->first()->uses_legacy_escaping)->toBeFalse()
        ->and($this->application->environment_variables()->where('key', 'OLD_EXPORT_VAR')->first()->uses_legacy_escaping)->toBeTrue();
});

test('transfer keeps whether a volume ignores the Compose driver options', function (?bool $exported, bool $expected) {
    LocalPersistentVolume::query()->update(['ignores_compose_driver_options' => (bool) $exported]);
    $bundle = $this->exporter->export($this->server);
    $storage = &$bundle['projects'][0]['environments'][0]['applications'][0]['persistent_storages'][0];
    expect($storage['ignores_compose_driver_options'])->toBe((bool) $exported);
    if ($exported === null) {
        // A bundle from a Coolify version without the flag: its volumes were created without driver options.
        unset($storage['ignores_compose_driver_options']);
    }
    unset($storage);
    $originalAppUuid = $this->application->uuid;

    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();
    Tag::query()->delete();
    ScheduledDatabaseBackup::query()->delete();
    ScheduledTask::query()->delete();
    $this->privateKey->delete();

    $this->importer->import($bundle, teamId: $this->team->id, dryRun: false, preserveUuids: true, adoptMode: true);

    expect(Application::where('uuid', $originalAppUuid)->sole()->persistentStorages()->sole()->ignores_compose_driver_options)->toBe($expected);
})->with([
    'existing volume' => [true, true],
    'new volume' => [false, false],
    'bundle without the flag' => [null, true],
]);

test('transfer keeps the link between an application volume and its sqlite database', function () {
    $sqlite = StandaloneSqlite::create([
        'name' => 'app-sqlite',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    LocalPersistentVolume::create([
        'name' => 'sqlite-data-'.$sqlite->uuid,
        'mount_path' => StandaloneSqlite::DATA_DIRECTORY,
        'standalone_sqlite_id' => $sqlite->id,
        'resource_type' => $this->application->getMorphClass(),
        'resource_id' => $this->application->id,
    ]);
    $bundle = $this->exporter->export($this->server);
    $originalAppName = $this->application->name;

    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $sqlite->forceDelete();
    $this->server->forceDelete();
    Tag::query()->delete();
    ScheduledDatabaseBackup::query()->delete();
    ScheduledTask::query()->delete();
    $this->privateKey->delete();

    $this->importer->import($bundle, teamId: $this->team->id, dryRun: false, preserveUuids: false, adoptMode: false);

    $importedSqlite = StandaloneSqlite::where('name', 'app-sqlite')->sole();
    $connectedVolume = Application::where('name', $originalAppName)->sole()
        ->persistentStorages()->where('mount_path', StandaloneSqlite::DATA_DIRECTORY)->sole();

    expect($connectedVolume->standalone_sqlite_id)->toBe($importedSqlite->id)
        ->and($connectedVolume->isSharedWithAnotherResource())->toBeTrue()
        ->and($importedSqlite->hasConnectedApplications())->toBeTrue()
        ->and($importedSqlite->persistentStorages()->sole()->isSharedWithAnotherResource())->toBeTrue();
});

test('secret manager links of applications and databases move with their integration token', function () {
    $token = IntegrationToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'vault',
        'token' => 'vault-root-token',
        'capabilities' => ['secrets'],
    ]);
    $this->application->secretManagerLink()->create(['integration_token_id' => $token->id, 'settings' => ['mount' => 'secret', 'path' => 'app']]);
    $this->database->secretManagerLink()->create(['integration_token_id' => $token->id, 'settings' => ['mount' => 'kv', 'path' => 'db']]);

    $bundle = $this->exporter->export($this->server);

    expect(collect($bundle['warnings'])->filter(fn (string $warning) => str_contains($warning, 'secret manager')))->toBeEmpty()
        ->and($bundle['integration_tokens'])->toHaveCount(1)
        ->and($bundle['integration_tokens'][0]['token'])->toBe('vault-root-token')
        ->and(data_get($bundle, 'projects.0.environments.0.databases.0.secret_manager_link.integration_token_uuid'))->toBe($token->uuid);

    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();
    Tag::query()->delete();
    ScheduledDatabaseBackup::query()->delete();
    ScheduledTask::query()->delete();
    $this->privateKey->delete();
    $token->delete();

    $this->importer->import($bundle, teamId: $this->team->id, dryRun: false, preserveUuids: true, adoptMode: true);

    $imported = IntegrationToken::where('uuid', $token->uuid)->where('team_id', $this->team->id)->firstOrFail();
    $application = Application::where('uuid', $this->application->uuid)->firstOrFail();
    $database = StandalonePostgresql::where('uuid', $this->database->uuid)->firstOrFail();
    expect($imported->token)->toBe('vault-root-token')
        ->and($application->secretManagerLink->integration_token_id)->toBe($imported->id)
        ->and($application->secretManagerLink->settings)->toBe(['mount' => 'secret', 'path' => 'app'])
        ->and($database->secretManagerLink->integration_token_id)->toBe($imported->id)
        ->and($database->secretManagerLink->settings)->toBe(['mount' => 'kv', 'path' => 'db']);
});

test('transfer keeps the Traefik certificate resolver of applications and service applications', function () {
    $this->application->settings->update(['traefik_certificate_resolver' => 'none']);
    $serviceApp = new ServiceApplication;
    $serviceApp->forceFill([
        'service_id' => $this->service->id,
        'name' => 'whoami',
        'fqdn' => 'https://whoami.example.com',
        'image' => 'traefik/whoami:latest',
        'traefik_certificate_resolver' => 'cloudflare',
        'status' => 'running:healthy',
    ]);
    $serviceApp->uuid = 'svc-app-resolver';
    $serviceApp->save();
    $originalAppUuid = $this->application->uuid;

    $bundle = $this->exporter->export($this->server);

    ServiceApplication::withoutEvents(function () {
        ServiceApplication::withTrashed()->get()->each->forceDelete();
    });
    DB::table('environment_variables')->delete();
    DB::table('local_persistent_volumes')->delete();
    DB::table('scheduled_database_backups')->delete();
    DB::table('scheduled_tasks')->delete();
    DB::table('services')->delete();
    DB::table('applications')->delete();
    DB::table('standalone_postgresqls')->delete();
    DB::table('standalone_dockers')->delete();
    DB::table('servers')->delete();
    $this->privateKey->delete();
    Tag::query()->delete();

    $this->importer->import($bundle, teamId: $this->team->id, preserveUuids: true, adoptMode: true);

    expect(Application::where('uuid', $originalAppUuid)->firstOrFail()->settings->traefik_certificate_resolver)->toBe('none')
        ->and(ServiceApplication::where('uuid', 'svc-app-resolver')->firstOrFail()->traefik_certificate_resolver)->toBe('cloudflare');
});

test('transfer keeps the cloud provider instance id of the server', function () {
    $this->server->forceFill(['hetzner_server_id' => 4242, 'vultr_instance_id' => 'vultr-7'])->save();
    $bundle = $this->exporter->export($this->server->fresh());

    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();

    $result = $this->importer->import($bundle, teamId: $this->team->id);

    $server = Server::where('uuid', $result['server_uuid'])->sole();
    expect((int) $server->hetzner_server_id)->toBe(4242)
        ->and($server->vultr_instance_id)->toBe('vultr-7');
});

test('adopt mode stops the import when a resource uuid is already used', function () {
    $bundle = $this->exporter->export($this->server);
    $appUuid = $this->application->uuid;

    // The server is gone, but the application row still holds its UUID on this instance.
    $this->service->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();

    expect(fn () => $this->importer->import($bundle, teamId: $this->team->id, adoptMode: true))
        ->toThrow(ValidationException::class, "The application UUID {$appUuid} cannot be used on this instance.");

    expect(Server::where('uuid', $bundle['server']['uuid'])->exists())->toBeFalse();
});

test('import rejects a database model class that is not a supported database type', function () {
    $bundle = $this->exporter->export($this->server);
    $bundle['projects'][0]['environments'][0]['databases'][] = [
        'type' => 'Unknown',
        'model' => Application::class,
        'uuid' => 'not-a-database',
        'attributes' => ['name' => 'not-a-database'],
        'destination_uuid' => $this->destination->uuid,
    ];

    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();

    expect(fn () => $this->importer->import($bundle, teamId: $this->team->id))
        ->toThrow(RuntimeException::class, 'Unsupported database type');

    expect(Application::where('uuid', 'not-a-database')->exists())->toBeFalse();
});

test('import rejects unsafe server connection fields', function (string $field, mixed $value) {
    $bundle = $this->exporter->export($this->server);
    $bundle['server'][$field] = $value;

    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();

    expect(fn () => $this->importer->import($bundle, teamId: $this->team->id))
        ->toThrow(ValidationException::class);
})->with([
    'ip with shell characters' => ['ip', '10.0.0.1;reboot'],
    'user with shell characters' => ['user', 'root$(id)'],
    'port out of range' => ['port', 70000],
]);

test('export refuses credentials of another team', function () {
    $otherKey = PrivateKey::factory()->create(['team_id' => Team::factory()->create()->id]);
    $this->application->forceFill(['private_key_id' => $otherKey->id])->save();

    expect(fn () => $this->exporter->export($this->server))
        ->toThrow(RuntimeException::class, 'does not belong to the server team');
});

test('transfer keeps maintenance mode, the server error page and swarm cluster, environment variable flags, and the Coolify default variables switch', function () {
    $this->application->forceFill(['is_maintenance_enabled' => true, 'maintenance_page' => '<h1>App down</h1>'])->saveQuietly();
    $this->service->forceFill(['is_maintenance_enabled' => true, 'maintenance_page' => '<h1>Service down</h1>', 'include_coolify_default_variables' => false])->saveQuietly();
    $this->application->settings->forceFill(['include_coolify_default_variables' => false])->saveQuietly();
    $this->database->forceFill(['include_coolify_default_variables' => false])->saveQuietly();
    $this->server->forceFill(['proxy_error_page' => '<h1>Proxy error</h1>', 'swarm_cluster' => 7])->saveQuietly();
    EnvironmentVariable::withoutEvents(function () {
        $env = new EnvironmentVariable;
        $env->forceFill([
            'key' => 'COMPOSE_NULL',
            'value' => null,
            'preserve_compose_null' => true,
            'version' => '4.0.0-beta.400',
            'resourceable_type' => Application::class,
            'resourceable_id' => $this->application->id,
        ]);
        $env->uuid = new_public_id();
        $env->save();
    });
    $appUuid = $this->application->uuid;
    $serviceUuid = $this->service->uuid;
    $databaseClass = $this->database::class;
    $databaseUuid = $this->database->uuid;

    $bundle = $this->exporter->export($this->server->fresh());

    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();

    $result = $this->importer->import($bundle, teamId: $this->team->id);

    $server = Server::where('uuid', $result['server_uuid'])->sole();
    $application = Application::where('uuid', $appUuid)->sole();
    $service = Service::where('uuid', $serviceUuid)->sole();
    $variable = $application->environment_variables()->where('key', 'COMPOSE_NULL')->sole();
    expect($application->is_maintenance_enabled)->toBeTrue()
        ->and($application->maintenance_page)->toBe('<h1>App down</h1>')
        ->and($service->is_maintenance_enabled)->toBeTrue()
        ->and($service->maintenance_page)->toBe('<h1>Service down</h1>')
        ->and($server->proxy_error_page)->toBe('<h1>Proxy error</h1>')
        ->and((int) $server->swarm_cluster)->toBe(7)
        ->and($variable->preserve_compose_null)->toBeTrue()
        ->and($variable->value)->toBeNull()
        ->and($variable->version)->toBe('4.0.0-beta.400')
        ->and($application->settings->include_coolify_default_variables)->toBeFalse()
        ->and($service->include_coolify_default_variables)->toBeFalse()
        ->and($databaseClass::where('uuid', $databaseUuid)->sole()->include_coolify_default_variables)->toBeFalse();
});

test('transfer keeps preview, backup, GitHub App runner fields and canvas positions when uuids change', function () {
    $githubApp = new GithubApp;
    $githubApp->forceFill([
        'name' => 'Runner App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'team_id' => $this->team->id,
        'organization' => 'acme',
        'organization_self_hosted_runners' => 'write',
        'actions' => 'read',
        'webhook_events' => ['workflow_job', 'push'],
        'runner_group_id' => 12,
    ]);
    $githubApp->uuid = 'runner-github-app';
    $githubApp->save();
    $this->application->forceFill(['source_type' => GithubApp::class, 'source_id' => $githubApp->id])->saveQuietly();

    $preview = new ApplicationPreview;
    $preview->forceFill([
        'application_id' => $this->application->id,
        'pull_request_id' => 7,
        'pull_request_html_url' => 'https://github.com/example/app/pull/7',
        'fqdn' => 'https://pr-7.app.example.com',
        'domain_port_overrides' => ['https://pr-7.app.example.com' => 8080],
        'max_restart_count' => 5,
    ]);
    $preview->uuid = 'preview-pr-7';
    $preview->saveQuietly();

    $this->backup->update(['missing_backup_notification_days' => 3]);
    ScheduledVolumeBackup::create([
        'backupable_type' => LocalPersistentVolume::class,
        'backupable_id' => $this->application->persistentStorages()->sole()->id,
        'team_id' => $this->team->id,
        'frequency' => '0 4 * * *',
        'missing_backup_notification_days' => 2,
    ]);

    $this->environment->forceFill(['canvas_positions' => [
        $this->application->uuid => ['x' => 10, 'y' => -20],
        $this->database->uuid => ['x' => 300, 'y' => 40],
        'resource-on-another-server' => ['x' => 1, 'y' => 1],
    ]])->save();

    $bundle = $this->exporter->export($this->server);

    expect($bundle['projects'][0]['environments'][0]['canvas_positions'])->toHaveCount(2);

    ApplicationPreview::withoutEvents(fn () => ApplicationPreview::withTrashed()->get()->each->forceDelete());
    DB::table('scheduled_volume_backups')->delete();
    DB::table('scheduled_database_backups')->delete();
    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();
    GithubApp::where('uuid', 'runner-github-app')->delete();

    $this->importer->import($bundle, teamId: $this->team->id, preserveUuids: false, adoptMode: false);

    $application = Application::where('name', 'my-app')->sole();
    $database = StandalonePostgresql::where('name', 'app-db')->sole();
    $importedPreview = $application->previews()->sole();
    $importedGithubApp = $application->source;
    expect($importedPreview->domain_port_overrides)->toBe(['https://pr-7.app.example.com' => 8080])
        ->and($importedPreview->max_restart_count)->toBe(5)
        ->and(ScheduledDatabaseBackup::where('database_id', $database->id)->sole()->missing_backup_notification_days)->toBe(3)
        ->and(ScheduledVolumeBackup::sole()->missing_backup_notification_days)->toBe(2)
        ->and($importedGithubApp)->toBeInstanceOf(GithubApp::class)
        ->and($importedGithubApp->organization_self_hosted_runners)->toBe('write')
        ->and($importedGithubApp->actions)->toBe('read')
        ->and($importedGithubApp->webhook_events)->toBe(['workflow_job', 'push'])
        ->and($importedGithubApp->runner_group_id)->toBe(12)
        ->and($application->uuid)->not->toBe($this->application->uuid)
        ->and($application->environment->canvas_positions)->toBe([
            $application->uuid => ['x' => 10, 'y' => -20],
            $database->uuid => ['x' => 300, 'y' => 40],
        ]);
});

test('transfer creates the referenced team shared variables in the target team', function () {
    $this->application->environment_variables()->where('key', 'APP_SECRET')->sole()->update(['value' => 'Bearer {{team.API_TOKEN}}']);
    EnvironmentVariable::withoutEvents(function () {
        $env = new EnvironmentVariable;
        $env->forceFill([
            'key' => 'SERVICE_URL',
            'value' => '{{ team.SERVICE_HOST }}/api',
            'resourceable_type' => Service::class,
            'resourceable_id' => $this->service->id,
        ]);
        $env->uuid = new_public_id();
        $env->save();
    });
    foreach (['API_TOKEN' => 'team-secret', 'SERVICE_HOST' => 'https://svc.example.com', 'UNUSED' => 'not-referenced'] as $key => $value) {
        SharedEnvironmentVariable::create(['key' => $key, 'value' => $value, 'type' => 'team', 'team_id' => $this->team->id, 'is_literal' => true]);
    }

    $bundle = $this->exporter->export($this->server);

    expect(collect($bundle['shared_environment_variables']['team'])->pluck('key')->sort()->values()->all())->toBe(['API_TOKEN', 'SERVICE_HOST']);

    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();
    $targetTeam = Team::factory()->create();

    $this->importer->import($bundle, teamId: $targetTeam->id);

    $teamVariables = SharedEnvironmentVariable::where('type', 'team')->where('team_id', $targetTeam->id)->get()->keyBy('key');
    expect($teamVariables->keys()->sort()->values()->all())->toBe(['API_TOKEN', 'SERVICE_HOST'])
        ->and($teamVariables['API_TOKEN']->value)->toBe('team-secret')
        ->and((bool) $teamVariables['API_TOKEN']->is_literal)->toBeTrue()
        ->and($teamVariables['SERVICE_HOST']->value)->toBe('https://svc.example.com');
});

test('transfer keeps the value of a team shared variable that already exists in the target team and warns', function () {
    $this->application->environment_variables()->where('key', 'APP_SECRET')->sole()->update(['value' => '{{team.API_TOKEN}}']);
    $variable = SharedEnvironmentVariable::create(['key' => 'API_TOKEN', 'value' => 'source-value', 'type' => 'team', 'team_id' => $this->team->id]);

    $bundle = $this->exporter->export($this->server);
    $variable->update(['value' => 'target-value']);

    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();

    $result = $this->importer->import($bundle, teamId: $this->team->id);

    expect(SharedEnvironmentVariable::where('type', 'team')->where('team_id', $this->team->id)->sole()->value)->toBe('target-value')
        ->and(collect($result['warnings'])->filter(fn (string $warning) => str_contains($warning, 'API_TOKEN')))->toHaveCount(1);
});

test('import warns when the git source, private key or S3 storage of a resource cannot be found', function () {
    $githubApp = new GithubApp;
    $githubApp->forceFill([
        'name' => 'Team GitHub App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'team_id' => $this->team->id,
    ]);
    $githubApp->uuid = 'missing-github-app';
    $githubApp->save();
    $deployKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'deploy-key',
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);
    $this->application->forceFill([
        'source_type' => GithubApp::class,
        'source_id' => $githubApp->id,
        'private_key_id' => $deployKey->id,
    ])->saveQuietly();
    $storage = S3Storage::create([
        'name' => 'Backups',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://93.184.216.34',
        'team_id' => $this->team->id,
    ]);
    $this->backup->update(['s3_storage_id' => $storage->id, 'save_s3' => true]);

    $bundle = $this->exporter->export($this->server);
    // The target instance cannot resolve these references.
    $bundle['github_apps'] = [];
    $bundle['s3_storages'] = [];
    $bundle['private_keys'] = collect($bundle['private_keys'])->reject(fn (array $key) => $key['uuid'] === $deployKey->uuid)->values()->all();

    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();

    $result = $this->importer->import($bundle, teamId: Team::factory()->create()->id);

    expect($result['warnings'])->toContain('Application my-app: its GitHub App was not found on this instance; set the source again.')
        ->and($result['warnings'])->toContain('Application my-app: its private key was not found on this instance; select the private key again.')
        ->and($result['warnings'])->toContain('Backup of database app-db: S3 upload was turned off because its S3 storage was not found.');
});

test('export warns about DNS records of another team', function () {
    $bundle = $this->exporter->export($this->server);
    expect(implode(' ', $bundle['warnings']))->not->toContain('DNS record');

    ManagedDnsRecord::factory()->create()->references()->create([
        'resource_type' => $this->application->getMorphClass(),
        'resource_id' => $this->application->id,
    ]);

    $bundle = $this->exporter->export($this->server->fresh());

    expect(implode(' ', $bundle['warnings']))->toContain('because its DNS provider token belongs to another team. The target instance does not manage it.');
});

/**
 * Removes the source copies of the resources of beforeEach, so the target can import them with their uuids.
 */
function removeTransferSourceResources(object $test): void
{
    $test->service->forceDelete();
    $test->application->forceDelete();
    $test->database->forceDelete();
    $test->server->forceDelete();
}

function transferTestGithubApp(Team $team, string $uuid, string $name): GithubApp
{
    $githubApp = new GithubApp;
    $githubApp->forceFill([
        'name' => $name,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'organization' => 'acme',
        'app_id' => 4242,
        'installation_id' => 4343,
        'team_id' => $team->id,
        'is_system_wide' => false,
        'private_key_id' => PrivateKey::factory()->create([
            'team_id' => $team->id,
            'name' => "{$name} key",
            'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
        ])->id,
    ]);
    $githubApp->uuid = $uuid;
    $githubApp->save();

    return $githubApp;
}

test('canvas links between transferred resources move with their environment and the other links are left out', function () {
    $user = User::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id, 'ip' => '10.55.0.11']);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->firstOrFail();
    $appOnOtherServer = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(),
        'name' => 'stays-here',
    ]);
    $link = fn (Model $source, Model $target, ?string $label) => ResourceLink::create([
        'environment_id' => $this->environment->id,
        'source_type' => $source->getMorphClass(),
        'source_id' => $source->id,
        'target_type' => $target->getMorphClass(),
        'target_id' => $target->id,
        'label' => $label,
        'created_by' => $user->id,
    ]);
    $appToDatabase = $link($this->application, $this->database, 'reads');
    $link($this->service, $this->application, null);
    $link($this->application, $appOnOtherServer, 'calls');

    $bundle = $this->exporter->export($this->server);

    expect($bundle['projects'][0]['environments'][0]['resource_links'])->toHaveCount(2)
        ->and($bundle['warnings'])->toContain('1 link between resources on the environment canvas is not transferred, because one of the linked resources stays on this instance.');

    removeTransferSourceResources($this);
    ResourceLink::query()->delete();
    $targetTeam = Team::factory()->create();

    $this->importer->import($bundle, teamId: $targetTeam->id);

    $environment = Project::where('team_id', $targetTeam->id)->sole()->environments()->sole();
    $application = Application::where('uuid', $this->application->uuid)->sole();
    $database = StandalonePostgresql::where('uuid', $this->database->uuid)->sole();
    $service = Service::where('uuid', $this->service->uuid)->sole();
    $links = ResourceLink::where('environment_id', $environment->id)->orderBy('id')->get();

    expect($links)->toHaveCount(2)
        ->and($links[0]->only(['uuid', 'source_type', 'source_id', 'target_type', 'target_id', 'label', 'created_by']))->toBe([
            'uuid' => $appToDatabase->uuid,
            'source_type' => $application->getMorphClass(),
            'source_id' => $application->id,
            'target_type' => $database->getMorphClass(),
            'target_id' => $database->id,
            'label' => 'reads',
            'created_by' => null,
        ])
        ->and($links[1]->only(['source_type', 'source_id', 'target_type', 'target_id', 'label']))->toBe([
            'source_type' => $service->getMorphClass(),
            'source_id' => $service->id,
            'target_type' => $application->getMorphClass(),
            'target_id' => $application->id,
            'label' => null,
        ]);
});

test('the project icon moves with the project into the icon storage of the target', function () {
    Storage::fake('images');
    $icon = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00transfer-icon";
    Storage::disk('images')->put("project-icons/{$this->project->uuid}/icon.jpg", $icon);
    $this->project->forceFill(['icon_path' => "project-icons/{$this->project->uuid}/icon.jpg", 'icon_storage_type' => 'local'])->saveQuietly();

    $bundle = $this->exporter->export($this->server);

    expect($bundle['projects'][0]['icon'])->toBe(['content_base64' => base64_encode($icon), 'extension' => 'jpg', 'mime' => 'image/jpeg']);

    removeTransferSourceResources($this);
    $targetTeam = Team::factory()->create();

    $this->importer->import($bundle, teamId: $targetTeam->id);

    $project = Project::where('team_id', $targetTeam->id)->sole();
    expect($project->uuid)->not->toBe($this->project->uuid)
        ->and($project->only(['icon_path', 'icon_storage_type', 'icon_s3_storage_id']))->toBe([
            'icon_path' => "project-icons/{$project->uuid}/icon.jpg",
            'icon_storage_type' => 'local',
            'icon_s3_storage_id' => null,
        ])
        ->and(Storage::disk('images')->get($project->icon_path))->toBe($icon);
});

test('a project icon that cannot be read or is too large is not exported and the export warns', function (?string $stored, string $warning) {
    Storage::fake('images');
    if ($stored !== null) {
        Storage::disk('images')->put('project-icons/transfer/icon.jpg', $stored);
    }
    $this->project->forceFill(['icon_path' => 'project-icons/transfer/icon.jpg', 'icon_storage_type' => 'local'])->saveQuietly();

    $bundle = $this->exporter->export($this->server);

    expect($bundle['projects'][0]['icon'])->toBeNull()
        ->and($bundle['warnings'])->toContain($warning);
})->with([
    'missing file' => [null, 'The icon of project Transfer Project cannot be read, so it is not transferred. Upload it again on the target instance.'],
    'larger than 2 MB' => [str_repeat('x', ServerTransferExporter::MAX_PROJECT_ICON_BYTES + 1), 'The icon of project Transfer Project is larger than 2 MB, so it is not transferred. Upload it again on the target instance.'],
]);

test('import stores only a JPEG project icon and warns about other content', function () {
    Storage::fake('images');
    $bundle = $this->exporter->export($this->server);
    $bundle['projects'][0]['icon'] = ['content_base64' => base64_encode('<svg onload="alert(1)"/>'), 'extension' => 'jpg', 'mime' => 'image/jpeg'];
    removeTransferSourceResources($this);
    $targetTeam = Team::factory()->create();

    $result = $this->importer->import($bundle, teamId: $targetTeam->id);

    expect(Project::where('team_id', $targetTeam->id)->sole()->icon_path)->toBeNull()
        ->and(Storage::disk('images')->allFiles())->toBe([])
        ->and($result['warnings'])->toContain('The icon of project Transfer Project was not imported, because it is not a JPEG image. Upload it again in the project settings.');
});

test('only environments with a resource of the server are exported', function () {
    SharedEnvironmentVariable::create(['key' => 'PROJECT_KEY', 'value' => 'project', 'type' => 'project', 'project_id' => $this->project->id, 'team_id' => $this->team->id]);
    SharedEnvironmentVariable::create(['key' => 'PRODUCTION_KEY', 'value' => 'production', 'type' => 'environment', 'environment_id' => $this->environment->id, 'team_id' => $this->team->id]);
    $staging = Environment::factory()->create(['project_id' => $this->project->id, 'name' => 'staging']);
    SharedEnvironmentVariable::create(['key' => 'STAGING_KEY', 'value' => 'staging', 'type' => 'environment', 'environment_id' => $staging->id, 'team_id' => $this->team->id]);
    $otherServer = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id, 'ip' => '10.55.0.11']);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->firstOrFail();
    Application::factory()->create([
        'environment_id' => $staging->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(),
        'name' => 'staging-app',
    ]);

    $bundle = $this->exporter->export($this->server);

    expect(collect($bundle['projects'][0]['environments'])->pluck('name')->all())->toBe([$this->environment->name])
        ->and(collect($bundle['projects'][0]['shared_environment_variables'])->pluck('key')->all())->toBe(['PROJECT_KEY'])
        ->and(collect($bundle['projects'][0]['environments'][0]['shared_environment_variables'])->pluck('key')->all())->toBe(['PRODUCTION_KEY']);

    removeTransferSourceResources($this);
    $targetTeam = Team::factory()->create();

    $this->importer->import($bundle, teamId: $targetTeam->id);

    $project = Project::where('team_id', $targetTeam->id)->sole();
    expect($project->environments()->pluck('name')->all())->toBe([$this->environment->name])
        ->and(Application::where('name', 'staging-app')->count())->toBe(1)
        ->and(SharedEnvironmentVariable::where('key', 'STAGING_KEY')->count())->toBe(1);
});

test('the GitHub runner config moves disabled with its GitHub App and the bundle lists the manual steps', function () {
    $githubApp = transferTestGithubApp($this->team, 'runner-only-app', 'Runner App');
    $gitlabApp = new GitlabApp;
    $gitlabApp->forceFill(['name' => 'Team GitLab', 'api_url' => 'https://gitlab.com/api/v4', 'html_url' => 'https://gitlab.com', 'team_id' => $this->team->id, 'is_system_wide' => false]);
    $gitlabApp->save();
    $this->application->forceFill(['source_type' => GitlabApp::class, 'source_id' => $gitlabApp->id])->saveQuietly();
    $config = GithubRunnerConfig::create([
        'server_id' => $this->server->id,
        'github_app_id' => $githubApp->id,
        'labels' => ['coolify', 'gpu'],
        'is_enabled' => true,
        'is_dedicated' => true,
        'allow_pull_requests' => true,
        'max_runners' => 4,
        'docker_mode' => 'dind',
        'runner_image' => 'ghcr.io/acme/runner:1',
        'cpu_limit' => '1.5',
        'memory_limit' => '2g',
        'capacity_wait_timeout' => 30,
        'idle_timeout' => 5,
        'job_timeout' => 120,
    ]);

    $bundle = $this->exporter->export($this->server);

    expect(collect($bundle['github_apps'])->pluck('uuid')->all())->toBe(['runner-only-app'])
        ->and(collect($bundle['private_keys'])->pluck('uuid'))->toContain($githubApp->privateKey->uuid)
        ->and($bundle['github_runner_config']['github_app_uuid'])->toBe('runner-only-app')
        ->and($bundle['post_transfer_checklist'])->toBe([
            'On GitHub, open the settings of GitHub App Runner App. Set the Webhook URL to {target_url}/webhooks/source/github/events, the Setup URL to {target_url}/webhooks/source/github/install, and the Callback URL to {target_url}/login/github/app.',
            'In GitLab, open Settings > Webhooks of every project that uses GitLab App Team GitLab, and set the webhook URL to {target_url}/webhooks/source/gitlab/events.',
            'After the GitHub App sends webhooks to {target_url}, open this server there, check the runner requirements, and enable GitHub runners. Enabling them stops runners that another Coolify instance started on this server.',
            'This instance keeps a copy of the SSH key of the server. On {target_url}, add a new SSH key to the server and select it there, then remove the old public key from ~/.ssh/authorized_keys on the server.',
        ])
        ->and(implode(' ', $bundle['warnings']))->not->toContain('webhook URL')
        ->and(implode(' ', $bundle['warnings']))->not->toContain('runner');

    removeTransferSourceResources($this);
    $githubApp->delete();
    $gitlabApp->delete();
    $targetTeam = Team::factory()->create();

    $result = $this->importer->import($bundle, teamId: $targetTeam->id);

    $importedApp = GithubApp::where('uuid', 'runner-only-app')->where('team_id', $targetTeam->id)->sole();
    $imported = GithubRunnerConfig::where('server_id', Server::where('uuid', $result['server_uuid'])->sole()->id)->sole();
    expect($imported->uuid)->toBe($config->uuid)
        ->and($imported->github_app_id)->toBe($importedApp->id)
        ->and($imported->is_enabled)->toBeFalse()
        ->and($imported->only(['labels', 'is_dedicated', 'allow_pull_requests', 'max_runners', 'runner_image', 'cpu_limit', 'memory_limit', 'capacity_wait_timeout', 'idle_timeout', 'job_timeout']))->toBe([
            'labels' => ['coolify', 'gpu'],
            'is_dedicated' => true,
            'allow_pull_requests' => true,
            'max_runners' => 4,
            'runner_image' => 'ghcr.io/acme/runner:1',
            'cpu_limit' => '1.5',
            'memory_limit' => '2g',
            'capacity_wait_timeout' => 30,
            'idle_timeout' => 5,
            'job_timeout' => 120,
        ])
        ->and($imported->docker_mode->value)->toBe('dind')
        ->and($result['checklist'])->toContain('On GitHub, open the settings of GitHub App Runner App. Set the Webhook URL to https://coolify-a.test/webhooks/source/github/events, the Setup URL to https://coolify-a.test/webhooks/source/github/install, and the Callback URL to https://coolify-a.test/login/github/app.')
        ->and(implode(' ', $result['warnings']))->not->toContain('webhook URL');
});

test('the GitHub runner config re-links a system-wide GitHub App of the target by uuid', function () {
    $systemWide = transferTestGithubApp($this->team, 'system-runner-app', 'System Runner App');
    $systemWide->forceFill(['is_system_wide' => true])->save();
    GithubRunnerConfig::create(['server_id' => $this->server->id, 'github_app_id' => $systemWide->id, 'labels' => ['coolify']]);

    $bundle = $this->exporter->export($this->server);
    expect($bundle['github_apps'])->toBe([]);
    removeTransferSourceResources($this);

    $result = $this->importer->import($bundle, teamId: Team::factory()->create()->id);

    expect(GithubRunnerConfig::where('server_id', Server::where('uuid', $result['server_uuid'])->sole()->id)->sole()->github_app_id)->toBe($systemWide->id);
});

test('the GitHub runner config is not imported when its GitHub App is missing on the target', function () {
    $githubApp = transferTestGithubApp($this->team, 'missing-runner-app', 'Runner App');
    GithubRunnerConfig::create(['server_id' => $this->server->id, 'github_app_id' => $githubApp->id, 'labels' => ['coolify']]);

    $bundle = $this->exporter->export($this->server);
    $bundle['github_apps'] = [];
    removeTransferSourceResources($this);
    $githubApp->delete();

    $result = $this->importer->import($bundle, teamId: Team::factory()->create()->id);

    expect(GithubRunnerConfig::count())->toBe(0)
        ->and($result['warnings'])->toContain('The GitHub runner settings of this server were not imported, because their GitHub App was not found on this instance. Set up GitHub runners on the server again.');
});

test('the checklist asks to move manual webhooks of git applications without a private App', function () {
    $this->application->forceFill(['source_type' => null, 'source_id' => null])->saveQuietly();

    $checklist = implode("\n", $this->exporter->export($this->server)['post_transfer_checklist']);

    expect($checklist)->toContain('If a repository of these applications has a manual webhook to Coolify, change its URL to the target: my-app.')
        ->toContain('{target_url}/webhooks/source/gitea/events/manual');
});

test('the checklist does not ask about manual webhooks for an application that deploys through a private GitHub App', function () {
    $githubApp = GithubApp::create([
        'name' => 'private-app', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com',
        'team_id' => $this->team->id, 'is_system_wide' => false, 'is_public' => false,
    ]);
    $this->application->forceFill(['source_type' => GithubApp::class, 'source_id' => $githubApp->id])->saveQuietly();

    $checklist = implode("\n", $this->exporter->export($this->server)['post_transfer_checklist']);

    expect($checklist)->not->toContain('manual webhook');
});

test('a claimed import starts the finish list and returns the server URL', function () {
    $bundle = $this->exporter->export($this->server);
    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();

    $result = $this->importer->import($bundle, teamId: $this->team->id);

    $server = Server::where('uuid', $result['server_uuid'])->firstOrFail();
    expect($result['server_url'])->toBe('https://coolify-a.test/server/'.$server->uuid)
        ->and(data_get($server->server_metadata, 'transfer.status'))->toBe('claimed')
        ->and(collect(data_get($server->server_metadata, 'transfer.finish.items'))->pluck('key'))->toContain('server_connection:server');
});

test('an application with a system-wide GitHub App that the target lacks is listed for a git source check', function () {
    $systemApp = GithubApp::create(['name' => 'Coolify GitHub', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com', 'team_id' => 0, 'is_system_wide' => true]);
    $this->application->forceFill(['source_type' => GithubApp::class, 'source_id' => $systemApp->id])->saveQuietly();
    $bundle = $this->exporter->export($this->server);
    $this->service->forceDelete();
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();
    $systemApp->delete();

    $result = $this->importer->import($bundle, teamId: $this->team->id);

    $server = Server::where('uuid', $result['server_uuid'])->firstOrFail();
    expect(data_get($bundle, 'projects.0.environments.0.applications.0.source.system_wide'))->toBeTrue()
        ->and(data_get($server->server_metadata, 'transfer.git_source_applications'))->toBe([$this->application->uuid])
        ->and(collect(data_get($server->server_metadata, 'transfer.finish.items'))->pluck('key'))->toContain("git_source:{$this->application->uuid}");
});
