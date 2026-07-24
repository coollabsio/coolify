<?php

use App\Actions\Database\BuildPostgresqlWalGEnvironment;
use App\Actions\Database\BuildPostgresqlWalGPostgresConfig;
use App\Actions\Database\ResolvePostgresqlDataDirectory;
use App\Actions\Database\SelectPostgresqlWalBaseBackupForTargetTime;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\ValidatePostgresqlWalGImage;
use App\Livewire\Project\Database\Postgresql\General;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PostgresqlWalBackupConfiguration;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);
    InstanceSettings::forceCreate(['id' => 0]);
    Storage::fake('ssh-keys');

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->database = createPostgresqlWalDeploymentDatabase(
        $this->team,
        $this->destination,
        'source-postgres',
        'ghcr.io/coollabsio/postgres-walg:16',
    );
    $this->storage = createPostgresqlWalDeploymentStorage($this->team);
    $this->configuration = createPostgresqlWalDeploymentConfiguration(
        $this->team,
        $this->database,
        $this->storage,
        16,
    );
});

it('builds a shell-safe WAL-G environment for the database repository', function () {
    $environment = BuildPostgresqlWalGEnvironment::run($this->configuration);

    expect($environment)
        ->toContain("WALG_S3_PREFIX='s3://test-bucket/coolify/postgresql/{$this->database->uuid}/pg16'")
        ->toContain("AWS_ACCESS_KEY_ID='test-key'")
        ->toContain("AWS_SECRET_ACCESS_KEY='test secret'\\''with quote'")
        ->toContain("AWS_REGION='us-east-1'")
        ->toContain("AWS_ENDPOINT='https://s3.example.com'")
        ->toContain('AWS_S3_FORCE_PATH_STYLE=true')
        ->toContain('WALG_PREVENT_WAL_OVERWRITE=true')
        ->toContain('WALG_DELTA_MAX_STEPS=0');
});

it('rejects environment generation after S3 storage is detached', function () {
    $this->configuration->update(['s3_storage_id' => null]);

    expect(fn () => BuildPostgresqlWalGEnvironment::run($this->configuration->fresh()))
        ->toThrow(RuntimeException::class, 'S3 storage');
});

it('builds managed archive settings after user PostgreSQL configuration', function () {
    $managedConfiguration = BuildPostgresqlWalGPostgresConfig::run($this->configuration);

    expect($managedConfiguration)->toBe(implode("\n", [
        'wal_level = replica',
        'archive_mode = on',
        'archive_timeout = 60',
        "archive_command = '/usr/local/bin/coolify-walg-archive %p'",
    ]));
});

it('builds restore settings with an explicit UTC target offset', function () {
    $targetTime = CarbonImmutable::parse('2026-07-24 15:30:00', 'Asia/Kolkata');

    $managedConfiguration = BuildPostgresqlWalGPostgresConfig::run($this->configuration, $targetTime);

    expect($managedConfiguration)->toBe(implode("\n", [
        "restore_command = '/usr/local/bin/coolify-walg-fetch %f %p'",
        "recovery_target_time = '2026-07-24 10:00:00+00'",
        "recovery_target_action = 'promote'",
    ]));
});

it('selects the newest base backup that finished at or before the target', function () {
    $selected = SelectPostgresqlWalBaseBackupForTargetTime::run([
        [
            'backup_name' => 'base_finished_after_target',
            'start_time' => '2026-07-24 09:00:00+00',
            'finish_time' => '2026-07-24 10:01:00+00',
        ],
        [
            'backup_name' => 'base_older',
            'start_time' => '2026-07-24 07:00:00+00',
            'finish_time' => '2026-07-24 08:00:00+00',
        ],
        [
            'backup_name' => 'base_selected',
            'start_time' => '2026-07-24 08:30:00+00',
            'finish_time' => '2026-07-24 09:30:00+00',
        ],
    ], CarbonImmutable::parse('2026-07-24 10:00:00', 'UTC'));

    expect($selected['backup_name'])->toBe('base_selected');
});

it('rejects restore targets without a completed base backup', function () {
    expect(fn () => SelectPostgresqlWalBaseBackupForTargetTime::run([
        [
            'backup_name' => 'base_too_new',
            'start_time' => '2026-07-24 09:00:00+00',
            'finish_time' => '2026-07-24 10:01:00+00',
        ],
    ], CarbonImmutable::parse('2026-07-24 10:00:00', 'UTC')))
        ->toThrow(RuntimeException::class, 'finished at or before');
});

it('validates supported WAL-G image tags', function (string $image, int $majorVersion) {
    expect(ValidatePostgresqlWalGImage::run($image))->toBe($majorVersion)
        ->and(ValidatePostgresqlWalGImage::run($image, $majorVersion))->toBe($majorVersion);
})->with([
    ['ghcr.io/coollabsio/postgres-walg:16', 16],
    ['ghcr.io/coollabsio/postgres-walg:17', 17],
    ['ghcr.io/coollabsio/postgres-walg:18', 18],
]);

it('rejects unsupported or mismatched WAL-G images', function (string $image, ?int $majorVersion) {
    expect(fn () => ValidatePostgresqlWalGImage::run($image, $majorVersion))
        ->toThrow(ValidationException::class);
})->with([
    ['postgres:16-bookworm', null],
    ['ghcr.io/coollabsio/postgres-walg:15', null],
    ['ghcr.io/coollabsio/postgres-walg:latest', null],
    ['ghcr.io/coollabsio/postgres-walg:16-extra', null],
    ['ghcr.io/coollabsio/postgres-walg:17', 16],
]);

it('derives PGDATA for an empty restore target', function (string $image, int $majorVersion, string $expected) {
    $database = createPostgresqlWalDeploymentDatabase(
        $this->team,
        $this->destination,
        "postgres-{$majorVersion}",
        $image,
    );
    createPostgresqlWalDeploymentConfiguration($this->team, $database, $this->storage, $majorVersion);

    expect(ResolvePostgresqlDataDirectory::run($database, populated: false))->toBe($expected);
})->with([
    ['ghcr.io/coollabsio/postgres-walg:16', 16, '/var/lib/postgresql/data'],
    ['ghcr.io/coollabsio/postgres-walg:17', 17, '/var/lib/postgresql/data'],
    ['ghcr.io/coollabsio/postgres-walg:18', 18, '/var/lib/postgresql/18/docker'],
]);

it('detects PGDATA from PG_VERSION for a populated cluster', function () {
    Process::fake([
        '*PG_VERSION*' => new FakeProcessResult(output: "/var/lib/postgresql/data/custom-cluster\n"),
        '*' => new FakeProcessResult,
    ]);

    expect(ResolvePostgresqlDataDirectory::run($this->database))->toBe('/var/lib/postgresql/data/custom-cluster');
});

it('keeps the legacy configuration hash byte-identical for non-PITR databases', function () {
    $database = createPostgresqlWalDeploymentDatabase(
        $this->team,
        $this->destination,
        'regular-postgres',
        'postgres:16-alpine',
    );
    $legacyHashInput = $database->image.$database->ports_mappings.$database->postgres_initdb_args.$database->postgres_host_auth_method;
    $healthCheckHash = new ReflectionMethod($database, 'healthCheckConfigurationHash');
    $legacyHashInput .= $healthCheckHash->invoke($database);
    $legacyHashInput .= json_encode($database->environment_variables()->get('value')->makeVisible('value')->sort());

    $database->isConfigurationChanged(save: true);

    expect($database->fresh()->config_hash)->toBe(md5($legacyHashInput));

    $database->postgres_conf = 'max_connections = 200';
    $database->save();

    expect($database->fresh()->isConfigurationChanged())->toBeFalse();
});

it('includes PITR and user PostgreSQL settings in the configuration hash', function () {
    $this->database->isConfigurationChanged(save: true);

    $this->database->postgres_conf = 'max_connections = 200';
    $this->database->save();
    expect($this->database->fresh()->isConfigurationChanged())->toBeTrue();

    $this->database->isConfigurationChanged(save: true);
    $this->configuration->update(['archive_timeout_seconds' => 120]);
    expect($this->database->fresh()->isConfigurationChanged())->toBeTrue();

    $this->database->isConfigurationChanged(save: true);
    $this->storage->update(['secret' => 'rotated-secret']);
    expect($this->database->fresh()->isConfigurationChanged())->toBeTrue();
});

it('locks the image of a PITR-enabled database server-side', function () {
    $this->database->image = 'ghcr.io/coollabsio/postgres-walg:17';

    expect(fn () => $this->database->save())->toThrow(ValidationException::class, 'cannot be changed');
    expect($this->database->fresh()->image)->toBe('ghcr.io/coollabsio/postgres-walg:16');
});

it('does not infer PITR when a regular database uses a WAL-G image', function () {
    $database = createPostgresqlWalDeploymentDatabase(
        $this->team,
        $this->destination,
        'regular-postgres',
        'postgres:16-alpine',
    );
    $database->image = 'ghcr.io/coollabsio/postgres-walg:16';
    $database->save();

    expect($database->fresh()->image)->toBe('ghcr.io/coollabsio/postgres-walg:16')
        ->and($database->walBackupConfiguration)->toBeNull();
});

it('disables image editing for a PITR-enabled database', function () {
    Livewire::test(General::class, ['database' => $this->database])
        ->assertSee('PITR database images are locked after creation.');
});

it('generates enabled WAL-G deployment files without logging S3 secrets', function () {
    Queue::fake();
    Process::fake(['*' => new FakeProcessResult]);
    $this->database->update(['postgres_conf' => 'max_connections = 200']);
    $temporaryFilesBefore = glob(sys_get_temp_dir().'/coolify-walg-*') ?: [];

    $action = StartPostgresql::make();
    $activity = $action->handle($this->database->fresh());

    $loggedCommands = (string) data_get($activity, 'properties.command');
    $writtenPostgresConfiguration = postgresqlWalDeploymentWrittenFile($action->commands, 'custom-postgres.conf');
    $compose = postgresqlWalDeploymentCompose($action->commands);
    $service = data_get($compose, 'services.'.$this->database->uuid);

    expect($loggedCommands)->not->toContain("test secret'with quote")
        ->and($writtenPostgresConfiguration)->toContain('max_connections = 200')
        ->and($writtenPostgresConfiguration)->toContain('archive_mode = on')
        ->and(strpos($writtenPostgresConfiguration, 'max_connections = 200'))
        ->toBeLessThan(strpos($writtenPostgresConfiguration, 'archive_mode = on'))
        ->and(data_get($service, 'volumes'))->toContain([
            'type' => 'bind',
            'source' => database_configuration_dir().'/'.$this->database->uuid.'/wal-g/env',
            'target' => '/etc/wal-g/env',
            'read_only' => true,
        ])
        ->and(data_get($service, 'volumes'))->toContain([
            'type' => 'bind',
            'source' => database_configuration_dir().'/'.$this->database->uuid.'/wal-g/coolify-walg-archive',
            'target' => '/usr/local/bin/coolify-walg-archive',
            'read_only' => true,
        ])
        ->and($temporaryFilesBefore)->toBe(glob(sys_get_temp_dir().'/coolify-walg-*') ?: []);

    Process::assertRan(fn ($process) => str_contains($process->command, 'scp ')
        && str_contains($process->command, '/wal-g/.env.'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'chmod 0600')
        && str_contains($process->command, 'chown 999:999')
        && str_contains($process->command, 'mv -f'));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, "test secret'with quote"));
});

it('forces archive mode off and removes the secret for disabled PITR', function () {
    Queue::fake();
    Process::fake(['*' => new FakeProcessResult]);
    $this->configuration->update(['enabled' => false]);

    $action = StartPostgresql::make();
    $action->handle($this->database->fresh());

    $loggedCommands = implode("\n", $action->commands);
    $compose = postgresqlWalDeploymentCompose($action->commands);
    $service = data_get($compose, 'services.'.$this->database->uuid);

    expect(data_get($service, 'command'))->toContain('archive_mode=off')
        ->and($loggedCommands)->toContain('/wal-g/env')
        ->and($loggedCommands)->toContain('rm -f')
        ->and(collect(data_get($service, 'volumes'))->contains(fn ($volume) => data_get($volume, 'target') === '/etc/wal-g/env'))
        ->toBeFalse();
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'scp '));
});

it('uses source repository settings and forces archiving off in restore mode', function () {
    Queue::fake();
    Process::fake(['*' => new FakeProcessResult]);
    $targetDatabase = createPostgresqlWalDeploymentDatabase(
        $this->team,
        $this->destination,
        'restore-target',
        'ghcr.io/coollabsio/postgres-walg:16',
    );
    createPostgresqlWalDeploymentConfiguration($this->team, $targetDatabase, $this->storage, 16)
        ->update(['enabled' => false, 'status' => 'disabled']);
    $targetTime = CarbonImmutable::parse('2026-07-24 10:00:00', 'UTC');

    $action = StartPostgresql::make();
    $activity = $action->handle($targetDatabase, $this->configuration, $targetTime);

    $compose = postgresqlWalDeploymentCompose($action->commands);
    $service = data_get($compose, 'services.'.$targetDatabase->uuid);
    $writtenPostgresConfiguration = postgresqlWalDeploymentWrittenFile($action->commands, 'custom-postgres.conf');

    expect(data_get($service, 'command'))->toContain('archive_mode=off')
        ->and($writtenPostgresConfiguration)->toContain("restore_command = '/usr/local/bin/coolify-walg-fetch %f %p'")
        ->and($writtenPostgresConfiguration)->toContain("recovery_target_time = '2026-07-24 10:00:00+00'")
        ->and((string) data_get($activity, 'properties.command'))->not->toContain("test secret'with quote")
        ->and(collect(data_get($service, 'volumes'))->contains(fn ($volume) => data_get($volume, 'target') === '/etc/wal-g/env'))
        ->toBeTrue();
});

it('can prepare restore configuration without starting or dispatching the container', function () {
    Queue::fake();
    Process::fake(['*' => new FakeProcessResult]);

    $action = StartPostgresql::make();
    $activity = $action->handle(
        $this->database,
        startContainer: false,
        execute: false,
    );
    $commands = implode("\n", $action->commands);

    expect($activity)->toBeNull()
        ->and($commands)->toContain('Database restore configuration prepared.')
        ->and($commands)->not->toContain('docker-compose.yml up -d');
});

function createPostgresqlWalDeploymentConfiguration(
    Team $team,
    StandalonePostgresql $database,
    S3Storage $storage,
    int $majorVersion,
): PostgresqlWalBackupConfiguration {
    return PostgresqlWalBackupConfiguration::create([
        'team_id' => $team->id,
        'standalone_postgresql_id' => $database->id,
        's3_storage_id' => $storage->id,
        'postgres_major_version' => $majorVersion,
    ]);
}

function createPostgresqlWalDeploymentDatabase(
    Team $team,
    StandaloneDocker $destination,
    string $name,
    string $image,
): StandalonePostgresql {
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return StandalonePostgresql::create([
        'name' => $name,
        'image' => $image,
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'is_public' => false,
        'is_log_drain_enabled' => false,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function createPostgresqlWalDeploymentStorage(Team $team): S3Storage
{
    return S3Storage::create([
        'name' => 'PostgreSQL WAL archive',
        'region' => 'us-east-1',
        'key' => 'test-key',
        'secret' => "test secret'with quote",
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
        'team_id' => $team->id,
    ]);
}

/**
 * @param  array<int, string>  $commands
 */
function postgresqlWalDeploymentWrittenFile(array $commands, string $target): string
{
    $command = collect($commands)->first(fn (string $command) => str_contains($command, 'tee ') && str_contains($command, $target));

    preg_match("/echo '([^']+)' \\| base64 -d \\| tee/", (string) $command, $matches);

    return base64_decode($matches[1] ?? '', strict: true) ?: '';
}

/**
 * @param  array<int, string>  $commands
 * @return array<string, mixed>
 */
function postgresqlWalDeploymentCompose(array $commands): array
{
    return Yaml::parse(postgresqlWalDeploymentWrittenFile($commands, 'docker-compose.yml'));
}
