<?php

use App\Actions\Database\StartInfluxdb;
use App\Livewire\Project\New\Select;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneInfluxdb;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.maintenance.store' => 'array', 'constants.ssh.mux_enabled' => false]);
    Process::fake();
    Queue::fake();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

/**
 * @return array<string, mixed>
 */
function startedInfluxdbCompose(): array
{
    $compose = null;
    Process::assertRan(function ($process) use (&$compose) {
        if (preg_match("/echo '([A-Za-z0-9+\/=]+)' \| base64 -d \| (?:sudo )?tee \S+\/docker-compose\.yml/", remoteProcessScript($process), $matches)) {
            $compose = Yaml::parse(base64_decode($matches[1]));
        }

        return true;
    });

    return $compose;
}

function patchInfluxdb(StandaloneInfluxdb $database, array $payload)
{
    $plainTextToken = Str::random(40);
    $token = test()->user->tokens()->create([
        'name' => 'influxdb-update-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['write'],
        'team_id' => test()->team->id,
    ]);

    return test()->withHeaders(['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken])
        ->patchJson('/api/v1/databases/'.$database->uuid, $payload);
}

test('a new influxdb gets generated credentials, the default org and bucket, and two volumes', function () {
    $database = create_standalone_influxdb($this->environment->id, $this->destination)->fresh();

    expect($database->name)->toBe('influxdb-database-'.$database->uuid)
        ->and($database->image)->toBe('influxdb:2.10-alpine')
        ->and($database->influxdb_admin_user)->toBe('influx')
        ->and($database->influxdb_org)->toBe('coolify')
        ->and($database->influxdb_bucket)->toBe('coolify')
        ->and($database->influxdb_admin_password)->toHaveLength(64)
        ->and($database->influxdb_admin_token)->toHaveLength(64)
        ->and($database->influxdb_admin_token)->not->toBe($database->influxdb_admin_password)
        ->and($database->internal_db_url)->toBe("http://{$database->uuid}:8086")
        ->and($database->persistentStorages()->pluck('mount_path', 'name')->all())->toBe([
            "influxdb-data-{$database->uuid}" => '/var/lib/influxdb2',
            "influxdb-config-{$database->uuid}" => '/etc/influxdb2',
        ]);
});

test('starting influxdb runs the official setup with the stored credentials', function () {
    $database = create_standalone_influxdb($this->environment->id, $this->destination, [
        'influxdb_org' => 'acme',
        'influxdb_bucket' => 'metrics',
    ]);

    StartInfluxdb::run($database->fresh(), activity()->log('Starting database'));

    $service = startedInfluxdbCompose()['services'][$database->uuid];
    expect($service['image'])->toBe('influxdb:2.10-alpine')
        ->and($service['environment'])->toContain(
            'DOCKER_INFLUXDB_INIT_MODE=setup',
            'DOCKER_INFLUXDB_INIT_USERNAME=influx',
            "DOCKER_INFLUXDB_INIT_PASSWORD={$database->influxdb_admin_password}",
            'DOCKER_INFLUXDB_INIT_ORG=acme',
            'DOCKER_INFLUXDB_INIT_BUCKET=metrics',
            "DOCKER_INFLUXDB_INIT_ADMIN_TOKEN={$database->influxdb_admin_token}",
        )
        ->and($service['healthcheck']['test'])->toBe(['CMD', 'influx', 'ping', '--host', 'http://127.0.0.1:8086'])
        ->and($service['volumes'])->toContain(
            "influxdb-data-{$database->uuid}:/var/lib/influxdb2",
            "influxdb-config-{$database->uuid}:/etc/influxdb2",
        );
});

test('the api changes influxdb credentials before the first start', function () {
    $database = create_standalone_influxdb($this->environment->id, $this->destination);

    patchInfluxdb($database, ['influxdb_bucket' => 'metrics', 'influxdb_org' => 'acme'])->assertOk();

    expect($database->fresh()->only(['influxdb_bucket', 'influxdb_org']))
        ->toBe(['influxdb_bucket' => 'metrics', 'influxdb_org' => 'acme']);
});

test('the api rejects influxdb credential changes after the first start', function (string $field, string $value) {
    $database = create_standalone_influxdb($this->environment->id, $this->destination, ['started_at' => now()]);
    $before = $database->fresh()->getAttribute($field);

    patchInfluxdb($database, [$field => $value])
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$field}.0", 'This value can only be changed before the first start.');

    expect($database->fresh()->getAttribute($field))->toBe($before);
})->with([
    'admin user' => ['influxdb_admin_user', 'admin'],
    'admin password' => ['influxdb_admin_password', 'NewPassword123'],
    'admin token' => ['influxdb_admin_token', 'NewToken123456'],
    'organization' => ['influxdb_org', 'acme'],
    'bucket' => ['influxdb_bucket', 'metrics'],
]);

test('the api still updates other influxdb fields after the first start', function () {
    $database = create_standalone_influxdb($this->environment->id, $this->destination, ['started_at' => now()]);

    patchInfluxdb($database, ['description' => 'metrics store'])->assertOk();

    expect($database->fresh()->description)->toBe('metrics store');
});

test('influxdb is offered on the new resource page', function () {
    $databases = collect((new Select)->loadServices()['databases'])->keyBy('id');

    expect($databases['influxdb']['name'])->toBe('InfluxDB')
        ->and($databases['influxdb']['logo'])->toBe(resource_logo_url('influxdb'));
});
