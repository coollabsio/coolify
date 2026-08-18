<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
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
use App\Models\Tag;
use App\Models\Team;
use App\Services\ServerTransfer\ServerTransferBundle;
use App\Services\ServerTransfer\ServerTransferExporter;
use App\Services\ServerTransfer\ServerTransferImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
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

test('import fails when server ip already exists', function () {
    $bundle = $this->exporter->export($this->server);

    $this->importer->import($bundle, teamId: $this->team->id);
})->throws(ValidationException::class);

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
        claim: false,
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
    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();

    $result = $this->importer->import($bundle, teamId: $this->team->id);

    $server = Server::where('uuid', $result['server_uuid'])->first();
    expect($server->private_key_id)->toBe($originalKeyId);
});

test('encrypted export decrypts for import', function () {
    $bundle = $this->exporter->export($this->server);
    $encrypted = ServerTransferBundle::encryptWithPassphrase($bundle, 'transfer-pass');

    $this->application->forceDelete();
    $this->database->forceDelete();
    $this->server->forceDelete();
    $this->privateKey->delete();

    $plain = ServerTransferBundle::decryptWithPassphrase($encrypted, 'transfer-pass');
    $result = $this->importer->import($plain, teamId: $this->team->id);

    expect(Server::where('uuid', $result['server_uuid'])->exists())->toBeTrue();
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
