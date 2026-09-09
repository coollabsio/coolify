<?php

/**
 * Seed a rich transfer-demo inventory on the current Coolify instance.
 * Run: php artisan tinker scripts/seed-transfer-demo.php
 * Or:  ./scripts/dev-instances exec a php artisan tinker --execute "require 'scripts/seed-transfer-demo.php';"
 */

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
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
use App\Models\SslCertificate;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Tag;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$teamId = 0;
$team = Team::find($teamId) ?? Team::query()->orderBy('id')->first();
if (! $team) {
    throw new RuntimeException('No team found.');
}
$teamId = $team->id;

$report = [];

// --- Private key (testing-host SSH) ---
$keyMaterial = <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY;

// Prefer existing testing-host key (same material) to avoid fingerprint uniqueness errors.
$key = PrivateKey::where('uuid', 'transfer-full-key')->first()
    ?? PrivateKey::where('name', 'Testing Host Key')->where('team_id', $teamId)->first()
    ?? PrivateKey::where('team_id', $teamId)->orderBy('id')->first();
if (! $key) {
    $key = PrivateKey::withoutEvents(function () use ($keyMaterial, $teamId) {
        $key = new PrivateKey;
        $key->forceFill([
            'name' => 'transfer-full-key',
            'description' => 'SSH key for transfer demo server (testing-host)',
            'private_key' => $keyMaterial,
            'team_id' => $teamId,
        ]);
        $key->uuid = 'transfer-full-key';
        $key->save();

        return $key;
    });
}
$report['private_key'] = $key->uuid;

// Idempotent cleanup of known demo UUIDs / leftover schedules.
DB::table('scheduled_volume_backups')->whereIn('uuid', [
    'demo-nixpacks-vol-backup', 'demo-svc-whoami-vol-backup',
])->delete();
DB::table('scheduled_database_backups')->whereIn('uuid', [
    'demo-postgres-backup', 'demo-mysql-backup', 'demo-svc-db-backup',
])->delete();
DB::table('application_previews')->where('uuid', 'demo-preview-101')->delete();
DB::table('local_file_volumes')->whereIn('uuid', [
    'demo-nixpacks-file', 'demo-pg-file-conf', 'demo-svc-whoami-file',
])->delete();
DB::table('github_apps')->where('uuid', 'transfer-team-github')->delete();

// Clean prior demo server if re-running (query builder avoids decrypting unrelated rows).
$old = Server::withTrashed()->where('uuid', 'transfer-full-demo')->first();
if ($old) {
    $destIds = StandaloneDocker::where('server_id', $old->id)->pluck('id')->all();
    DB::table('additional_destinations')->where('server_id', $old->id)->delete();

    $appIds = Application::withTrashed()
        ->where('destination_type', StandaloneDocker::class)
        ->whereIn('destination_id', $destIds ?: [0])
        ->pluck('id');
    if ($appIds->isNotEmpty()) {
        ApplicationPreview::withTrashed()->whereIn('application_id', $appIds)->forceDelete();
        ScheduledTask::whereIn('application_id', $appIds)->delete();
        Application::withTrashed()->whereIn('id', $appIds)->forceDelete();
    }

    $serviceIds = Service::withTrashed()->where('server_id', $old->id)->pluck('id');
    if ($serviceIds->isNotEmpty()) {
        ServiceApplication::withTrashed()->whereIn('service_id', $serviceIds)->forceDelete();
        ServiceDatabase::withTrashed()->whereIn('service_id', $serviceIds)->forceDelete();
        ScheduledTask::whereIn('service_id', $serviceIds)->delete();
        Service::withTrashed()->whereIn('id', $serviceIds)->forceDelete();
    }

    $dbTables = [
        'standalone_postgresqls',
        'standalone_mysqls',
        'standalone_redis',
        'standalone_mongodbs',
    ];
    foreach ($dbTables as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }
        $ids = DB::table($table)
            ->where('destination_type', StandaloneDocker::class)
            ->whereIn('destination_id', $destIds ?: [0])
            ->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('scheduled_database_backups')
                ->whereIn('database_id', $ids)
                ->delete();
            DB::table($table)->whereIn('id', $ids)->delete();
        }
    }

    DB::table('local_persistent_volumes')
        ->whereIn('resource_id', $destIds ?: [0])
        ->delete();
    SslCertificate::where('server_id', $old->id)->delete();
    SharedEnvironmentVariable::where('server_id', $old->id)->delete();
    StandaloneDocker::where('server_id', $old->id)->delete();
    $old->forceDelete();
}

// --- Server ---
$server = Server::create([
    'name' => 'transfer-full-demo',
    'description' => 'Full inventory for A→B server transfer testing',
    'ip' => 'testing-host',
    'user' => 'root',
    'port' => 22,
    'team_id' => $teamId,
    'private_key_id' => $key->id,
]);
$server->uuid = 'transfer-full-demo';
$server->save();
$server->settings->forceFill([
    'is_reachable' => true,
    'is_usable' => true,
    'wildcard_domain' => 'https://demo.transfer.local',
])->save();
$report['server'] = $server->uuid;

// --- Destinations ---
$dest = StandaloneDocker::where('server_id', $server->id)->first()
    ?? StandaloneDocker::create([
        'name' => 'coolify',
        'network' => 'coolify',
        'server_id' => $server->id,
    ]);
$dest->uuid = 'transfer-full-dest';
$dest->saveQuietly();
$report['destination'] = $dest->uuid;

// --- Project / environments ---
$project = Project::firstOrCreate(
    ['name' => 'Transfer Full Demo', 'team_id' => $teamId],
    ['description' => 'Resources to migrate between Coolify instances']
);
$project->uuid = $project->uuid ?: new_public_id();
$production = $project->environments()->where('name', 'production')->first()
    ?? Environment::create(['name' => 'production', 'project_id' => $project->id, 'description' => 'prod']);
$staging = $project->environments()->where('name', 'staging')->first()
    ?? Environment::create(['name' => 'staging', 'project_id' => $project->id, 'description' => 'staging']);
$report['project'] = $project->uuid ?? $project->name;
$report['environments'] = [$production->name, $staging->name];

// Shared env vars
SharedEnvironmentVariable::updateOrCreate(
    ['type' => 'server', 'server_id' => $server->id, 'key' => 'DEMO_SERVER_TOKEN'],
    ['value' => 'server-shared-secret', 'team_id' => $teamId, 'is_literal' => true, 'comment' => 'server-level']
);
SharedEnvironmentVariable::updateOrCreate(
    ['type' => 'project', 'project_id' => $project->id, 'key' => 'DEMO_PROJECT_API'],
    ['value' => 'project-shared-secret', 'team_id' => $teamId, 'is_literal' => true]
);
SharedEnvironmentVariable::updateOrCreate(
    ['type' => 'environment', 'environment_id' => $production->id, 'key' => 'DEMO_ENV_FLAG'],
    ['value' => 'production', 'team_id' => $teamId, 'is_literal' => true]
);

// Tags
$tagCritical = Tag::firstOrCreate(['name' => 'critical', 'team_id' => $teamId], ['uuid' => new_public_id()]);
$tagDemo = Tag::firstOrCreate(['name' => 'transfer-demo', 'team_id' => $teamId], ['uuid' => new_public_id()]);

// Team-scoped GitHub App (not system-wide)
$ghApp = GithubApp::where('uuid', 'transfer-team-github')->first();
if (! $ghApp) {
    $ghApp = new GithubApp;
    $ghApp->forceFill([
        'name' => 'Transfer Team GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
        'is_system_wide' => false,
        'team_id' => $teamId,
        'app_id' => 99001,
        'installation_id' => 88001,
        'client_id' => 'Iv1.transfer-demo',
        'client_secret' => 'gh-client-secret-demo',
        'webhook_secret' => 'gh-webhook-secret-demo',
        'private_key_id' => $key->id,
    ]);
    $ghApp->uuid = 'transfer-team-github';
    $ghApp->save();
}
$report['github_app'] = $ghApp->uuid;

// ========== APPLICATIONS ==========
// 1) Nixpacks git app with GH source
$appNix = Application::create([
    'name' => 'demo-nixpacks-app',
    'git_repository' => 'https://github.com/coollabsio/coolify-examples',
    'git_branch' => 'nodejs',
    'build_pack' => 'nixpacks',
    'ports_exposes' => '3000',
    'fqdn' => 'https://nixpacks.demo.transfer.local',
    'environment_id' => $production->id,
    'destination_id' => $dest->id,
    'destination_type' => $dest->getMorphClass(),
    'source_type' => GithubApp::class,
    'source_id' => $ghApp->id,
    'status' => 'exited',
    'description' => 'Nixpacks app with GitHub App source',
]);
$appNix->uuid = 'demo-nixpacks-app';
$appNix->save();
$appNix->tags()->syncWithoutDetaching([$tagDemo->id, $tagCritical->id]);
if ($appNix->settings) {
    $appNix->settings->forceFill(['is_auto_deploy_enabled' => true, 'is_force_https_enabled' => true])->save();
}
EnvironmentVariable::withoutEvents(function () use ($appNix) {
    $e = new EnvironmentVariable;
    $e->forceFill([
        'key' => 'APP_SECRET',
        'value' => 'nixpacks-app-secret-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $appNix->id,
        'is_runtime' => true,
        'is_buildtime' => true,
        'is_preview' => false,
    ]);
    $e->uuid = new_public_id();
    $e->save();
    $e2 = new EnvironmentVariable;
    $e2->forceFill([
        'key' => 'PREVIEW_ONLY',
        'value' => 'preview-secret',
        'resourceable_type' => Application::class,
        'resourceable_id' => $appNix->id,
        'is_runtime' => true,
        'is_buildtime' => false,
        'is_preview' => true,
    ]);
    $e2->uuid = new_public_id();
    $e2->save();
});
$volNix = new LocalPersistentVolume;
$volNix->forceFill([
    'name' => 'demo-nixpacks-data',
    'mount_path' => '/app/data',
    'resource_type' => $appNix->getMorphClass(),
    'resource_id' => $appNix->id,
]);
$volNix->uuid = new_public_id();
$volNix->save();
LocalFileVolume::withoutEvents(function () use ($appNix) {
    $f = new LocalFileVolume;
    $f->forceFill([
        'fs_path' => './config.json',
        'mount_path' => '/app/config.json',
        'content' => '{"demo":true,"from":"transfer-seed"}',
        'is_directory' => false,
        'resource_type' => $appNix->getMorphClass(),
        'resource_id' => $appNix->id,
    ]);
    $f->uuid = 'demo-nixpacks-file';
    $f->save();
});
ScheduledTask::create([
    'uuid' => new_public_id(),
    'name' => 'nightly-cleanup',
    'command' => 'php artisan cache:clear',
    'frequency' => '0 3 * * *',
    'application_id' => $appNix->id,
    'team_id' => $teamId,
    'enabled' => true,
]);
ScheduledVolumeBackup::create([
    'uuid' => 'demo-nixpacks-vol-backup',
    'backupable_type' => $volNix->getMorphClass(),
    'backupable_id' => $volNix->id,
    'team_id' => $teamId,
    'frequency' => '0 4 * * 0',
    'enabled' => true,
    'save_s3' => false,
]);
$preview = new ApplicationPreview;
$preview->forceFill([
    'application_id' => $appNix->id,
    'pull_request_id' => 101,
    'pull_request_html_url' => 'https://github.com/coollabsio/coolify-examples/pull/101',
    'fqdn' => 'https://pr-101.nixpacks.demo.transfer.local',
    'status' => 'exited',
    'git_type' => 'github',
]);
$preview->uuid = 'demo-preview-101';
$preview->save();
$previewVol = new LocalPersistentVolume;
$previewVol->forceFill([
    'name' => 'demo-preview-101-data',
    'mount_path' => '/app/data',
    'resource_type' => $preview->getMorphClass(),
    'resource_id' => $preview->id,
]);
$previewVol->uuid = new_public_id();
$previewVol->save();

// 2) Dockerfile app
$appDocker = Application::create([
    'name' => 'demo-dockerfile-app',
    'git_repository' => 'https://github.com/coollabsio/coolify-examples',
    'git_branch' => 'main',
    'build_pack' => 'dockerfile',
    'ports_exposes' => '80',
    'fqdn' => 'https://dockerfile.demo.transfer.local',
    'environment_id' => $production->id,
    'destination_id' => $dest->id,
    'destination_type' => $dest->getMorphClass(),
    'dockerfile' => "FROM nginx:alpine\nEXPOSE 80\n",
    'status' => 'exited',
]);
$appDocker->uuid = 'demo-dockerfile-app';
$appDocker->save();
$appDocker->tags()->syncWithoutDetaching([$tagDemo->id]);

// 3) Docker image / static-ish
$appImage = Application::create([
    'name' => 'demo-image-app',
    'git_repository' => 'coollabsio/coolify-examples',
    'git_branch' => 'main',
    'build_pack' => 'dockercompose',
    'ports_exposes' => '80',
    'fqdn' => 'https://compose.demo.transfer.local',
    'environment_id' => $staging->id,
    'destination_id' => $dest->id,
    'destination_type' => $dest->getMorphClass(),
    'docker_compose_raw' => "services:\n  web:\n    image: traefik/whoami\n    ports:\n      - '80'\n",
    'docker_compose' => "services:\n  web:\n    image: traefik/whoami\n    ports:\n      - '80'\n",
    'status' => 'exited',
]);
$appImage->uuid = 'demo-compose-app';
$appImage->save();

// 4) Deploy-key style app (private_key on application)
$appDeployKey = Application::create([
    'name' => 'demo-deploykey-app',
    'git_repository' => 'git@github.com:example/private-app.git',
    'git_branch' => 'main',
    'build_pack' => 'nixpacks',
    'ports_exposes' => '3000',
    'environment_id' => $production->id,
    'destination_id' => $dest->id,
    'destination_type' => $dest->getMorphClass(),
    'private_key_id' => $key->id,
    'status' => 'exited',
]);
$appDeployKey->uuid = 'demo-deploykey-app';
$appDeployKey->save();

$report['applications'] = [
    $appNix->uuid,
    $appDocker->uuid,
    $appImage->uuid,
    $appDeployKey->uuid,
];

// ========== DATABASES ==========
$mkDb = function (string $class, string $uuid, string $name, array $extra) use ($production, $dest, $tagCritical, $teamId) {
    return $class::withoutEvents(function () use ($class, $uuid, $name, $extra, $production, $dest, $tagCritical, $teamId) {
        $db = new $class;
        $db->forceFill(array_merge([
            'name' => $name,
            'environment_id' => $production->id,
            'destination_id' => $dest->id,
            'destination_type' => $dest->getMorphClass(),
            'status' => 'exited',
        ], $extra));
        $db->uuid = $uuid;
        $db->save();
        if (method_exists($db, 'tags')) {
            $db->tags()->syncWithoutDetaching([$tagCritical->id]);
        }
        if (method_exists($db, 'persistentStorages')) {
            $vol = new LocalPersistentVolume;
            $vol->forceFill([
                'name' => $uuid.'-data',
                'mount_path' => match ($class) {
                    StandalonePostgresql::class => '/var/lib/postgresql/data',
                    StandaloneMysql::class => '/var/lib/mysql',
                    StandaloneRedis::class => '/data',
                    StandaloneMongodb::class => '/data/db',
                    default => '/data',
                },
                'resource_type' => $db->getMorphClass(),
                'resource_id' => $db->id,
            ]);
            $vol->uuid = new_public_id();
            $vol->save();
        }
        if (method_exists($db, 'fileStorages') && $class === StandalonePostgresql::class) {
            LocalFileVolume::withoutEvents(function () use ($db) {
                $f = new LocalFileVolume;
                $f->forceFill([
                    'fs_path' => './pg-conf.d',
                    'mount_path' => '/etc/postgresql/conf.d',
                    'content' => null,
                    'is_directory' => true,
                    'resource_type' => $db->getMorphClass(),
                    'resource_id' => $db->id,
                ]);
                $f->uuid = 'demo-pg-file-conf';
                $f->save();
            });
        }
        if (method_exists($db, 'scheduledBackups') && in_array($class, [StandalonePostgresql::class, StandaloneMysql::class], true)) {
            ScheduledDatabaseBackup::create([
                'uuid' => $uuid.'-backup',
                'team_id' => $teamId,
                'enabled' => true,
                'save_s3' => false,
                'frequency' => '0 2 * * *',
                'databases_to_backup' => $extra['postgres_db'] ?? $extra['mysql_database'] ?? 'app',
                'database_type' => $db->getMorphClass(),
                'database_id' => $db->id,
            ]);
        }
        EnvironmentVariable::withoutEvents(function () use ($db, $name) {
            $e = new EnvironmentVariable;
            $e->forceFill([
                'key' => 'DB_LABEL',
                'value' => $name,
                'resourceable_type' => $db::class,
                'resourceable_id' => $db->id,
                'is_runtime' => true,
                'is_buildtime' => false,
            ]);
            $e->uuid = new_public_id();
            $e->save();
        });

        return $db;
    });
};

$pg = $mkDb(StandalonePostgresql::class, 'demo-postgres', 'demo-postgres', [
    'postgres_user' => 'demo',
    'postgres_password' => 'pg-secret-password',
    'postgres_db' => 'demoddb',
]);
$mysql = $mkDb(StandaloneMysql::class, 'demo-mysql', 'demo-mysql', [
    'mysql_root_password' => 'mysql-root-secret',
    'mysql_user' => 'demo',
    'mysql_password' => 'mysql-user-secret',
    'mysql_database' => 'demoddb',
]);
$redis = $mkDb(StandaloneRedis::class, 'demo-redis', 'demo-redis', [
    'image' => 'redis:7-alpine',
]);
// Redis password is stored as env var, not a column.
EnvironmentVariable::withoutEvents(function () use ($redis) {
    $e = new EnvironmentVariable;
    $e->forceFill([
        'key' => 'REDIS_PASSWORD',
        'value' => 'redis-secret-password',
        'resourceable_type' => StandaloneRedis::class,
        'resourceable_id' => $redis->id,
        'is_runtime' => true,
        'is_buildtime' => false,
    ]);
    $e->uuid = new_public_id();
    $e->save();
});
$mongo = $mkDb(StandaloneMongodb::class, 'demo-mongo', 'demo-mongo', [
    'mongo_initdb_root_username' => 'root',
    'mongo_initdb_root_password' => 'mongo-root-secret',
    'mongo_initdb_database' => 'demoddb',
    'image' => 'mongo:7',
]);
$report['databases'] = [$pg->uuid, $mysql->uuid, $redis->uuid, $mongo->uuid];

// ========== SERVICE STACK ==========
$service = Service::create([
    'name' => 'demo-service-stack',
    'environment_id' => $production->id,
    'destination_id' => $dest->id,
    'destination_type' => $dest->getMorphClass(),
    'server_id' => $server->id,
    'docker_compose_raw' => <<<'YAML'
services:
  whoami:
    image: traefik/whoami
    environment:
      WHOAMI_NAME: transfer-demo
  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_PASSWORD: service-db-secret
YAML,
    'docker_compose' => <<<'YAML'
services:
  whoami:
    image: traefik/whoami
  db:
    image: postgres:16-alpine
YAML,
]);
$service->uuid = 'demo-service-stack';
$service->save();
$service->tags()->syncWithoutDetaching([$tagDemo->id]);

EnvironmentVariable::withoutEvents(function () use ($service) {
    $e = new EnvironmentVariable;
    $e->forceFill([
        'key' => 'SERVICE_TOKEN',
        'value' => 'service-level-secret',
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
        'is_runtime' => true,
    ]);
    $e->uuid = new_public_id();
    $e->save();
});
ScheduledTask::create([
    'uuid' => new_public_id(),
    'name' => 'service-ping',
    'command' => 'echo ping',
    'frequency' => '*/30 * * * *',
    'service_id' => $service->id,
    'team_id' => $teamId,
    'enabled' => true,
    'container' => 'whoami',
]);

$svcApp = new ServiceApplication;
$svcApp->forceFill([
    'service_id' => $service->id,
    'name' => 'whoami',
    'human_name' => 'Whoami',
    'description' => 'Nested service app',
    'fqdn' => 'https://whoami.demo.transfer.local',
    'image' => 'traefik/whoami:latest',
    'ports' => '80',
    'exposes' => '80',
    'required_fqdn' => true,
    'is_gzip_enabled' => true,
    'is_stripprefix_enabled' => true,
    'status' => 'exited',
]);
$svcApp->uuid = 'demo-svc-whoami';
$svcApp->save();
EnvironmentVariable::withoutEvents(function () use ($svcApp) {
    $e = new EnvironmentVariable;
    $e->forceFill([
        'key' => 'WHOAMI_NAME',
        'value' => 'nested-whoami-secret',
        'resourceable_type' => ServiceApplication::class,
        'resourceable_id' => $svcApp->id,
        'is_runtime' => true,
    ]);
    $e->uuid = new_public_id();
    $e->save();
});
$svcAppVol = new LocalPersistentVolume;
$svcAppVol->forceFill([
    'name' => 'demo-svc-whoami-data',
    'mount_path' => '/data',
    'resource_type' => $svcApp->getMorphClass(),
    'resource_id' => $svcApp->id,
]);
$svcAppVol->uuid = new_public_id();
$svcAppVol->save();
LocalFileVolume::withoutEvents(function () use ($svcApp) {
    $f = new LocalFileVolume;
    $f->forceFill([
        'fs_path' => './whoami.env',
        'mount_path' => '/whoami.env',
        'content' => "NAME=transfer\n",
        'is_directory' => false,
        'resource_type' => $svcApp->getMorphClass(),
        'resource_id' => $svcApp->id,
    ]);
    $f->uuid = 'demo-svc-whoami-file';
    $f->save();
});
ScheduledVolumeBackup::create([
    'uuid' => 'demo-svc-whoami-vol-backup',
    'backupable_type' => $svcAppVol->getMorphClass(),
    'backupable_id' => $svcAppVol->id,
    'team_id' => $teamId,
    'frequency' => '0 5 * * *',
    'enabled' => true,
    'save_s3' => false,
]);

$svcDb = new ServiceDatabase;
$svcDb->forceFill([
    'service_id' => $service->id,
    'name' => 'db',
    'human_name' => 'Service Postgres',
    'image' => 'postgres:16-alpine',
    'ports' => '5432',
    'exposes' => '5432',
    'public_port' => 15432,
    'is_public' => false,
    'custom_type' => 'postgresql',
    'status' => 'exited',
]);
$svcDb->uuid = 'demo-svc-db';
$svcDb->save();
$svcDbVol = new LocalPersistentVolume;
$svcDbVol->forceFill([
    'name' => 'demo-svc-db-data',
    'mount_path' => '/var/lib/postgresql/data',
    'resource_type' => $svcDb->getMorphClass(),
    'resource_id' => $svcDb->id,
]);
$svcDbVol->uuid = new_public_id();
$svcDbVol->save();
ScheduledDatabaseBackup::create([
    'uuid' => 'demo-svc-db-backup',
    'team_id' => $teamId,
    'enabled' => true,
    'save_s3' => false,
    'frequency' => '0 1 * * *',
    'databases_to_backup' => 'postgres',
    'database_type' => $svcDb->getMorphClass(),
    'database_id' => $svcDb->id,
]);

$report['service'] = $service->uuid;
$report['service_applications'] = [$svcApp->uuid];
$report['service_databases'] = [$svcDb->uuid];

// ========== SSL cert (server-level) ==========
SslCertificate::create([
    'ssl_certificate' => "-----BEGIN CERTIFICATE-----\nMIIDemoTransferCertPlaceholder\n-----END CERTIFICATE-----\n",
    'ssl_private_key' => "-----BEGIN PRIVATE KEY-----\nMIIDemoTransferKeyPlaceholder\n-----END PRIVATE KEY-----\n",
    'configuration_dir' => '/data/coolify/proxy',
    'mount_path' => '/etc/ssl/certs',
    'common_name' => 'demo.transfer.local',
    'valid_until' => now()->addYear(),
    'is_ca_certificate' => false,
    'server_id' => $server->id,
    'resource_type' => null,
    'resource_id' => null,
]);
$report['ssl_certificates'] = 1;

// Guarantee no additional destinations
$extraDestCount = DB::table('additional_destinations')->where('server_id', $server->id)->count();
$report['additional_destinations'] = $extraDestCount;

$report['counts'] = [
    'applications' => Application::whereIn('uuid', $report['applications'])->count(),
    'databases' => 4,
    'services' => 1,
    'service_apps' => ServiceApplication::where('service_id', $service->id)->count(),
    'service_dbs' => ServiceDatabase::where('service_id', $service->id)->count(),
    'scheduled_tasks' => ScheduledTask::where('application_id', $appNix->id)->orWhere('service_id', $service->id)->count(),
    'scheduled_db_backups' => ScheduledDatabaseBackup::whereIn('uuid', [
        'demo-postgres-backup', 'demo-mysql-backup', 'demo-svc-db-backup',
    ])->count(),
    'volume_backups' => ScheduledVolumeBackup::whereIn('uuid', [
        'demo-nixpacks-vol-backup', 'demo-svc-whoami-vol-backup',
    ])->count(),
    'previews' => ApplicationPreview::where('uuid', 'demo-preview-101')->count(),
    'tags' => $appNix->tags()->count(),
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
