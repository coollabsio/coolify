<?php

use App\Jobs\ValidateAndInstallServerJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\CloudProviderToken;
use App\Models\DnsProviderZone;
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
use App\Models\SslCertificate;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\SwarmDocker;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Services\ServerTransfer\ServerTransferCoverage;
use App\Services\ServerTransfer\ServerTransferExporter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ServerTransferFuzzer;
use Tests\Support\ServerTransferSnapshot;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Saves a record without model events. Required columns that $attributes leaves out get a dummy value.
 *
 * @param  class-string<Model>  $modelClass
 * @param  array<string, mixed>  $attributes
 */
function transferCoverageRecord(string $modelClass, array $attributes = []): Model
{
    $model = new $modelClass;
    foreach (Schema::getColumns($model->getTable()) as $column) {
        $name = $column['name'];
        if (array_key_exists($name, $attributes) || $column['nullable'] || $column['default'] !== null || $name === 'id' || $column['auto_increment']) {
            continue;
        }
        // The SQLite test schema reports dates as text, so the model casts decide first.
        $type = strtolower($column['type_name']);
        $attributes[$name] = match (true) {
            $name === 'uuid' => new_public_id(),
            $model->hasCast($name, ['date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp']) => now(),
            $model->hasCast($name, ['bool', 'boolean']) || str_contains($type, 'bool') => false,
            $model->hasCast($name, ['array', 'json', 'collection', 'object']) || str_contains($type, 'json') => [],
            str_contains($type, 'int') => 1,
            default => 'coverage_'.$name,
        };
    }

    return $modelClass::withoutEvents(function () use ($model, $attributes) {
        $model->forceFill($attributes)->save();

        return $model;
    });
}

/**
 * Every key that the records at these bundle paths contain, also the keys of their `attributes` object.
 * A record with a `model` key counts only for that model.
 *
 * @param  array<string, mixed>  $bundle
 * @param  list<string>  $paths
 * @return array{keys: list<string>, records: int}
 */
function transferCoverageBundleKeys(array $bundle, array $paths, string $modelClass): array
{
    $records = collect($paths)
        ->flatMap(function (string $path) use ($bundle) {
            $found = data_get($bundle, $path);

            // A path to one record returns the record itself; a path with `*` returns a list of records.
            return is_array($found) && ! array_is_list($found) ? [$found] : Arr::wrap($found);
        })
        ->filter(fn ($record) => is_array($record) && ! array_is_list($record))
        ->filter(fn (array $record) => ! isset($record['model']) || $record['model'] === $modelClass);

    $keys = $records->flatMap(fn (array $record) => [...array_keys($record), ...array_keys((array) ($record['attributes'] ?? []))]);

    return ['keys' => $keys->unique()->values()->all(), 'records' => $records->count()];
}

beforeEach(function () {
    Server::flushIdentityMap();
    Process::fake();
    Storage::fake('ssh-keys');
    Storage::fake('images');
    Queue::fake([ValidateAndInstallServerJob::class]);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true, 'fqdn' => 'https://coolify-a.test']);

    $team = Team::factory()->create();
    $teamId = ['team_id' => $team->id];
    $privateKey = PrivateKey::factory()->create($teamId);
    $deployKey = PrivateKey::factory()->create([...$teamId, 'private_key' => generateSSHKey('ed25519')['private']]);
    $token = CloudProviderToken::factory()->create($teamId);
    $this->server = Server::factory()->create([...$teamId, 'private_key_id' => $privateKey->id, 'cloud_provider_token_id' => $token->id, 'ip' => '10.88.0.10']);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    transferCoverageRecord(SwarmDocker::class, ['server_id' => $this->server->id]);
    transferCoverageRecord(SslCertificate::class, ['server_id' => $this->server->id]);

    $project = Project::factory()->create($teamId);
    Storage::disk('images')->put("project-icons/{$project->uuid}/icon.jpg", "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00coverage");
    $project->forceFill(['icon_path' => "project-icons/{$project->uuid}/icon.jpg", 'icon_storage_type' => 'local'])->saveQuietly();
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    $onDestination = ['environment_id' => $environment->id, 'destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass()];

    $githubApp = transferCoverageRecord(GithubApp::class, [...$teamId, 'is_system_wide' => false, 'private_key_id' => $deployKey->id]);
    $gitlabApp = transferCoverageRecord(GitlabApp::class, [...$teamId, 'is_system_wide' => false]);
    $application = Application::factory()->create([...$onDestination, 'source_type' => GithubApp::class, 'source_id' => $githubApp->id, 'private_key_id' => $deployKey->id]);
    Application::factory()->create([...$onDestination, 'source_type' => GitlabApp::class, 'source_id' => $gitlabApp->id]);
    $giteaApp = transferCoverageRecord(GiteaApp::class, [...$teamId, 'is_system_wide' => false]);
    Application::factory()->create([...$onDestination, 'source_type' => GiteaApp::class, 'source_id' => $giteaApp->id]);
    $preview = transferCoverageRecord(ApplicationPreview::class, ['application_id' => $application->id]);

    $s3 = transferCoverageRecord(S3Storage::class, $teamId);
    $service = Service::factory()->create([...$onDestination, 'server_id' => $this->server->id]);
    transferCoverageRecord(ResourceLink::class, [
        'environment_id' => $environment->id,
        'source_type' => $application->getMorphClass(), 'source_id' => $application->id,
        'target_type' => $service->getMorphClass(), 'target_id' => $service->id,
    ]);
    transferCoverageRecord(GithubRunnerConfig::class, ['server_id' => $this->server->id, 'github_app_id' => $githubApp->id, 'labels' => ['coverage']]);
    transferCoverageRecord(ServiceApplication::class, ['service_id' => $service->id]);
    transferCoverageRecord(ServiceDatabase::class, ['service_id' => $service->id]);

    $databases = collect(ServerTransferCoverage::TRANSFERRED)->keys()
        ->filter(fn (string $class) => str_starts_with(class_basename($class), 'Standalone') && ! in_array($class, [StandaloneDocker::class], true))
        ->map(fn (string $class) => transferCoverageRecord($class, $onDestination));

    $volume = transferCoverageRecord(LocalPersistentVolume::class, ['resource_type' => $application->getMorphClass(), 'resource_id' => $application->id]);
    transferCoverageRecord(LocalFileVolume::class, ['resource_type' => $application->getMorphClass(), 'resource_id' => $application->id]);
    transferCoverageRecord(ScheduledVolumeBackup::class, [...$teamId, 'backupable_type' => $volume->getMorphClass(), 'backupable_id' => $volume->id, 's3_storage_id' => $s3->id]);
    transferCoverageRecord(ScheduledTask::class, [...$teamId, 'application_id' => $application->id]);
    transferCoverageRecord(ScheduledTask::class, [...$teamId, 'service_id' => $service->id]);
    transferCoverageRecord(ScheduledDatabaseBackup::class, [...$teamId, 'database_type' => $databases->first()->getMorphClass(), 'database_id' => $databases->first()->id, 's3_storage_id' => $s3->id]);

    $tag = transferCoverageRecord(Tag::class, $teamId);
    foreach ([$application, $databases->first(), $service] as $tagged) {
        $tagged->tags()->attach($tag->id);
    }

    foreach ([$application, $preview->application, $databases->first(), $service] as $resource) {
        transferCoverageRecord(EnvironmentVariable::class, ['resourceable_type' => $resource->getMorphClass(), 'resourceable_id' => $resource->id, 'key' => 'COVERAGE_'.fake()->unique()->numberBetween(), 'value' => '{{team.COVERAGE_TEAM}}']);
    }
    transferCoverageRecord(EnvironmentVariable::class, ['resourceable_type' => $application->getMorphClass(), 'resourceable_id' => $application->id, 'key' => 'COVERAGE_PREVIEW', 'value' => 'x', 'is_preview' => true]);
    transferCoverageRecord(SharedEnvironmentVariable::class, [...$teamId, 'type' => 'team', 'key' => 'COVERAGE_TEAM']);
    transferCoverageRecord(SharedEnvironmentVariable::class, [...$teamId, 'type' => 'server', 'server_id' => $this->server->id]);
    transferCoverageRecord(SharedEnvironmentVariable::class, [...$teamId, 'type' => 'project', 'project_id' => $project->id]);
    transferCoverageRecord(SharedEnvironmentVariable::class, [...$teamId, 'type' => 'environment', 'environment_id' => $environment->id]);

    $cloudflareToken = IntegrationToken::factory()->create([...$teamId, 'provider' => 'cloudflare', 'capabilities' => ['dns', 'tunnel'], 'metadata' => ['automatic_dns' => true]]);
    $secretsToken = IntegrationToken::factory()->create([...$teamId, 'provider' => 'infisical', 'capabilities' => ['secrets'], 'metadata' => ['client_id' => 'coverage']]);
    $zone = DnsProviderZone::factory()->create(['integration_token_id' => $cloudflareToken->id]);
    ManagedDnsRecord::factory()->owned()->create(['dns_provider_zone_id' => $zone->id])->addReference($application);
    $application->secretManagerLink()->create(['integration_token_id' => $secretsToken->id, 'settings' => ['project_id' => 'coverage', 'environment' => 'prod']]);
    $this->server->settings->forceFill(['cloudflare_tunnel_integration_token_id' => $cloudflareToken->id])->saveQuietly();

    $this->bundle = app(ServerTransferExporter::class)->export($this->server->fresh());
});

test('every model has a server transfer decision', function () {
    $models = collect(glob(app_path('Models/*.php')))
        ->map(fn (string $file) => 'App\\Models\\'.basename($file, '.php'))
        ->filter(fn (string $class) => is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract());

    $undecided = $models
        ->reject(fn (string $class) => array_key_exists($class, ServerTransferCoverage::TRANSFERRED)
            || array_key_exists($class, ServerTransferCoverage::NOT_TRANSFERRED))
        ->values()->all();

    expect($undecided)->toBe([], 'Add these models to the server transfer (ServerTransferExporter/Importer and ServerTransferCoverage::TRANSFERRED), or to ServerTransferCoverage::NOT_TRANSFERRED with a reason.');
});

test('every column of a transferred model is in the bundle or has a reason why not', function (string $modelClass) {
    ['keys' => $exported, 'records' => $records] = transferCoverageBundleKeys($this->bundle, ServerTransferCoverage::TRANSFERRED[$modelClass], $modelClass);
    expect($records)->toBeGreaterThan(0, "The coverage fixture exports no {$modelClass}. Add a record of it to the beforeEach of this test.");

    $notExported = [...ServerTransferCoverage::ALWAYS_NOT_EXPORTED, ...array_keys(ServerTransferCoverage::NOT_EXPORTED_COLUMNS[$modelClass] ?? [])];
    $columns = Schema::getColumnListing((new $modelClass)->getTable());

    expect(array_values(array_diff($columns, $exported, $notExported)))
        ->toBe([], "These {$modelClass} columns are not in the transfer bundle. Export and import them (with a round-trip test), or add them to ServerTransferCoverage::NOT_EXPORTED_COLUMNS with a reason.")
        ->and(array_values(array_intersect(array_keys(ServerTransferCoverage::NOT_EXPORTED_COLUMNS[$modelClass] ?? []), $exported)))
        ->toBe([], "These {$modelClass} columns are exported, so remove them from ServerTransferCoverage::NOT_EXPORTED_COLUMNS.")
        ->and(array_values(array_diff(array_keys(ServerTransferCoverage::NOT_EXPORTED_COLUMNS[$modelClass] ?? []), $columns)))
        ->toBe([], "These {$modelClass} columns no longer exist, so remove them from ServerTransferCoverage::NOT_EXPORTED_COLUMNS.");
})->with(array_keys(ServerTransferCoverage::TRANSFERRED));

test('every exported value arrives unchanged on the target', function () {
    $keep = [
        Server::class => ['ip', 'port', 'user', 'name'],
        PrivateKey::class => ['private_key', 'fingerprint'],
        GithubApp::class => ['is_system_wide'],
        GitlabApp::class => ['is_system_wide'],
        GiteaApp::class => ['is_system_wide'],
        EnvironmentVariable::class => ['key', 'value', 'is_preview'],
        SharedEnvironmentVariable::class => ['key', 'type'],
        Environment::class => ['canvas_positions'],
    ];
    $values = [
        Server::class => ['proxy' => ['type' => 'TRAEFIK', 'status' => 'running', 'redirect_enabled' => false, 'redirect_url' => 'https://fuzz.example.test']],
        Application::class => ['fqdn' => 'https://fuzz-a.example.test,https://fuzz-b.example.test', 'domain_port_overrides' => null, 'noindex_domains' => ['https://fuzz-b.example.test']],
        ApplicationPreview::class => ['fqdn' => 'https://pr-fuzz.example.test', 'domain_port_overrides' => null],
        ServiceApplication::class => ['fqdn' => 'https://fuzz-service.example.test', 'domain_port_overrides' => null, 'noindex_domains' => []],
        GithubRunnerConfig::class => ['labels' => ['fuzz-label'], 'max_runners' => 5, 'runner_image' => 'ghcr.io/fuzz/runner:2', 'cpu_limit' => '1.5', 'memory_limit' => '2g', 'capacity_wait_timeout' => 33, 'idle_timeout' => 44, 'job_timeout' => 55],
        LocalFileVolume::class => ['fs_path' => '/data/coolify/applications/fuzz/app.conf', 'mount_path' => '/etc/fuzz/app.conf', 'chown' => '1000:1000', 'chmod' => '0640'],
        // The import validates these values like the API does.
        GithubApp::class => ['html_url' => 'https://github.fuzz.example.test', 'api_url' => 'https://api.github.fuzz.example.test'],
        GitlabApp::class => ['html_url' => 'https://gitlab.fuzz.example.test', 'api_url' => 'https://gitlab.fuzz.example.test/api/v4'],
        GiteaApp::class => ['html_url' => 'https://gitea.fuzz.example.test', 'api_url' => 'https://gitea.fuzz.example.test/api/v1'],
        ScheduledTask::class => ['frequency' => '*/7 * * * *'],
        ScheduledDatabaseBackup::class => ['frequency' => '0 3 * * *'],
        ScheduledVolumeBackup::class => ['frequency' => '0 4 * * *'],
        StandalonePostgresql::class => ['postgres_user' => 'fuzz_user', 'postgres_db' => 'fuzz_db', 'ports_mappings' => '15432:5432', 'public_port' => 15432],
        StandaloneMongodb::class => ['mongo_initdb_root_username' => 'fuzz_user', 'mongo_initdb_database' => 'fuzz_db', 'ports_mappings' => '17017:27017', 'public_port' => 17017],
        StandaloneMysql::class => ['mysql_user' => 'fuzz_user', 'mysql_database' => 'fuzz_db', 'ports_mappings' => '13306:3306', 'public_port' => 13306],
        StandaloneMariadb::class => ['mariadb_user' => 'fuzz_user', 'mariadb_database' => 'fuzz_db', 'ports_mappings' => '13307:3306', 'public_port' => 13307],
        StandaloneClickhouse::class => ['clickhouse_admin_user' => 'fuzz_user', 'ports_mappings' => '18123:8123', 'public_port' => 18123],
        StandaloneRedis::class => ['ports_mappings' => '16379:6379', 'public_port' => 16379],
        StandaloneKeydb::class => ['ports_mappings' => '16380:6379', 'public_port' => 16380],
        StandaloneDragonfly::class => ['ports_mappings' => '16381:6379', 'public_port' => 16381],
    ];
    foreach (array_keys(ServerTransferCoverage::TRANSFERRED) as $class) {
        $class::query()->withoutGlobalScopes()->get()
            // Coolify sets the predefined server variables itself.
            ->reject(fn (Model $record) => $record instanceof SharedEnvironmentVariable && str_starts_with($record->key, 'COOLIFY_'))
            ->each(fn (Model $record) => ServerTransferFuzzer::fill($record, $keep[$class] ?? [], $values[$class] ?? []));
    }
    $s3 = S3Storage::query()->firstOrFail();
    ScheduledDatabaseBackup::query()->firstOrFail()->s3Storages()->attach($s3->id);
    ScheduledVolumeBackup::query()->firstOrFail()->s3Storages()->attach($s3->id);
    // Coolify sets is_shared when it saves a variable.
    EnvironmentVariable::query()->get()->each(fn (EnvironmentVariable $variable) => $variable->forceFill(['is_shared' => $variable->is_shared])->saveQuietly());
    $teamId = $this->server->team_id;
    $user = User::factory()->create();
    Team::find($teamId)->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => Team::find($teamId)]);
    $token = $user->createToken('round-trip', ['*', 'read:sensitive'])->plainTextToken;
    $before = ServerTransferSnapshot::take($this->server->uuid);
    $bundle = app(ServerTransferExporter::class)->export($this->server->fresh());

    Schema::disableForeignKeyConstraints();
    foreach (array_keys(ServerTransferCoverage::TRANSFERRED) as $class) {
        DB::table((new $class)->getTable())->delete();
    }
    foreach (['taggables', 'scheduled_database_backup_s3_storage', 'scheduled_volume_backup_s3_storage'] as $pivot) {
        DB::table($pivot)->delete();
    }
    Schema::enableForeignKeyConstraints();
    Server::flushIdentityMap();

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
        ->postJson('/api/v1/servers/import', ['bundle' => $bundle]);
    expect($response->status())->toBe(201, (string) $response->json('message'));

    $changedOnPurpose = [
        'last_online_at' => 'The import sets the status, which sets the time.',
        'GithubRunnerConfig.is_enabled' => 'The target disables the runners until the user enables them.',
    ];
    expect(ServerTransferSnapshot::diff($before, ServerTransferSnapshot::take($this->server->uuid), $changedOnPurpose)['unexplained'])->toBe([]);
});
