<?php

use App\Actions\Database\StartInfluxdb;
use App\Enums\NewDatabaseTypes;
use App\Enums\NewResourceTypes;
use App\Livewire\Project\New\Select;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneInfluxdb;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], []));
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

test('influxdb is registered as a selectable standalone database type', function () {
    expect(DATABASE_TYPES)->toContain('influxdb');
    expect(STANDALONE_DATABASE_MODELS)->toHaveKey('influxdb');
    expect(STANDALONE_DATABASE_MODELS['influxdb'])->toBe(StandaloneInfluxdb::class);
    expect(NewDatabaseTypes::tryFrom('influxdb'))->toBe(NewDatabaseTypes::INFLUXDB);
    expect(NewResourceTypes::tryFrom('influxdb'))->toBe(NewResourceTypes::INFLUXDB);
});

test('influxdb appears in the database section of the new resource page', function () {
    $databases = collect((new Select)->loadServices()['databases'])->keyBy('id');

    expect($databases)->toHaveKey('influxdb');
    expect($databases['influxdb']['name'])->toBe('InfluxDB');
    expect($databases['influxdb']['logo'])->toBe(asset('svgs/resources/influxdb.svg'));
});

test('create_standalone_influxdb generates credentials and persistent volumes', function () {
    $database = create_standalone_influxdb($this->environment->id, $this->destination);

    expect($database)->toBeInstanceOf(StandaloneInfluxdb::class);
    expect($database->name)->toStartWith('influxdb-database-');
    expect($database->influxdb_admin_user)->toBe('influx');
    expect($database->influxdb_admin_password)->not->toBeEmpty();
    expect($database->influxdb_admin_token)->not->toBeEmpty();
    expect($database->influxdb_admin_token)->not->toBe($database->influxdb_admin_password);
    expect($database->influxdb_org)->toBe('coolify');
    expect($database->influxdb_bucket)->toBe('coolify');
    expect($database->image)->toBe('influxdb:2.7-alpine');

    $volumes = $database->persistentStorages()->pluck('mount_path', 'name');
    expect($volumes)->toHaveCount(2);
    expect($volumes["influxdb-data-{$database->uuid}"])->toBe('/var/lib/influxdb2');
    expect($volumes["influxdb-config-{$database->uuid}"])->toBe('/etc/influxdb2');
});

test('influxdb credentials are encrypted at rest and hidden when serialized', function () {
    $database = create_standalone_influxdb($this->environment->id, $this->destination);

    $raw = DB::table('standalone_influxdbs')->where('id', $database->id)->first();
    expect($raw->influxdb_admin_password)->not->toBe($database->influxdb_admin_password);
    expect($raw->influxdb_admin_token)->not->toBe($database->influxdb_admin_token);

    $serialized = $database->toArray();
    expect($serialized)->not->toHaveKey('influxdb_admin_password');
    expect($serialized)->not->toHaveKey('influxdb_admin_token');
});

test('influxdb exposes the http api as its connection url', function () {
    $database = create_standalone_influxdb($this->environment->id, $this->destination);

    expect($database->type())->toBe('standalone-influxdb');
    expect($database->database_type)->toBe('standalone-influxdb');
    expect($database->internal_db_url)->toBe("http://{$database->uuid}:8086");
    expect($database->external_db_url)->toBeNull();
});

test('influxdb supports scheduled backups and defaults to its own bucket', function () {
    $database = create_standalone_influxdb($this->environment->id, $this->destination);

    expect($database->isBackupSolutionAvailable())->toBeTrue();

    $backup = ScheduledDatabaseBackup::create([
        'enabled' => true,
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
        'team_id' => $this->team->id,
        'databases_to_backup' => $database->influxdb_bucket,
    ]);

    expect($backup->databases_to_backup)->toBe('coolify');
    expect($database->scheduledBackups()->pluck('uuid'))->toContain($backup->uuid);
});

test('influxdb is reachable through the environment, project and server database lists', function () {
    $database = create_standalone_influxdb($this->environment->id, $this->destination);

    expect($this->environment->fresh()->databases()->pluck('uuid'))->toContain($database->uuid);
    expect($this->project->fresh()->databases()->pluck('uuid'))->toContain($database->uuid);
    expect($this->server->fresh()->databases()->pluck('uuid'))->toContain($database->uuid);
    expect(getResourceByUuid($database->uuid, $this->team->id)?->id)->toBe($database->id);
});

test('influxdb start action seeds the official setup environment variables', function () {
    $database = create_standalone_influxdb($this->environment->id, $this->destination);

    $action = new StartInfluxdb;
    $action->database = $database;

    $method = new ReflectionMethod(StartInfluxdb::class, 'generate_environment_variables');
    $method->setAccessible(true);
    $envs = collect($method->invoke($action));

    expect($envs)->toContain('DOCKER_INFLUXDB_INIT_MODE=setup');
    expect($envs)->toContain("DOCKER_INFLUXDB_INIT_USERNAME={$database->influxdb_admin_user}");
    expect($envs)->toContain("DOCKER_INFLUXDB_INIT_PASSWORD={$database->influxdb_admin_password}");
    expect($envs)->toContain("DOCKER_INFLUXDB_INIT_ORG={$database->influxdb_org}");
    expect($envs)->toContain("DOCKER_INFLUXDB_INIT_BUCKET={$database->influxdb_bucket}");
    expect($envs)->toContain("DOCKER_INFLUXDB_INIT_ADMIN_TOKEN={$database->influxdb_admin_token}");
});

test('influxdb healthcheck uses CMD exec-form, not CMD-SHELL', function () {
    $source = file_get_contents(base_path('app/Actions/Database/StartInfluxdb.php'));

    expect($source)->not->toContain('CMD-SHELL');
    expect($source)->toContain("'CMD', 'influx', 'ping'");
});

test('influxdb without a domain generates no proxy labels', function () {
    $database = create_standalone_influxdb($this->environment->id, $this->destination);

    expect($database->fqdn)->toBeNull();
    expect(generateLabelsDatabase($database, $database->httpPort())->all())->toBe([]);
});

test('influxdb with a domain routes the http api through the proxy', function () {
    $database = create_standalone_influxdb($this->environment->id, $this->destination);
    $database->fqdn = 'https://influx.example.com';
    $database->save();

    $labels = generateLabelsDatabase($database, $database->httpPort());

    expect($labels)->toContain('traefik.enable=true');
    expect($labels)->toContain("traefik.http.routers.https-0-{$database->uuid}.rule=Host(`influx.example.com`) && PathPrefix(`/`)");
    expect($labels)->toContain("traefik.http.services.https-0-{$database->uuid}.loadbalancer.server.port=8086");
    expect($labels)->toContain("traefik.http.routers.https-0-{$database->uuid}.tls.certresolver=letsencrypt");
    expect($labels->filter(fn ($label) => str_starts_with($label, 'caddy_0.')))->not->toBeEmpty();
});

test('influxdb domain is exposed as the external connection url and changes the config hash', function () {
    $database = create_standalone_influxdb($this->environment->id, $this->destination);
    $database->isConfigurationChanged(true);

    $database->fqdn = 'https://influx.example.com';
    $database->save();

    expect($database->external_db_url)->toBe('https://influx.example.com');
    expect($database->isConfigurationChanged())->toBeTrue();
});

test('influxdb domains conflict with domains already used by an application', function () {
    $database = create_standalone_influxdb($this->environment->id, $this->destination);
    $database->fqdn = 'https://influx.example.com';

    expect(checkDomainUsage(resource: $database)['hasConflicts'])->toBeFalse();

    Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://influx.example.com',
    ]);

    $result = checkDomainUsage(resource: $database);
    expect($result['hasConflicts'])->toBeTrue();
    expect($result['conflicts'][0]['domain'])->toBe('https://influx.example.com');
});

test('another influxdb cannot silently reuse an assigned domain', function () {
    $first = create_standalone_influxdb($this->environment->id, $this->destination);
    $first->fqdn = 'https://influx.example.com';
    $first->save();

    $second = create_standalone_influxdb($this->environment->id, $this->destination);
    $second->fqdn = 'https://influx.example.com';

    $result = checkDomainUsage(resource: $second);
    expect($result['hasConflicts'])->toBeTrue();
    expect($result['conflicts'][0]['resource_name'])->toBe($first->name);

    // The database that already owns the domain must not conflict with itself.
    expect(checkDomainUsage(resource: $first->fresh())['hasConflicts'])->toBeFalse();
});
