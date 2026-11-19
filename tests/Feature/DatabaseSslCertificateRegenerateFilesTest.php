<?php

use App\Helpers\SslHelper;
use App\Jobs\RegenerateSslCertJob;
use App\Livewire\Project\Database\Mongodb\StatusInfo as MongodbStatusInfo;
use App\Livewire\Project\Database\Postgresql\StatusInfo as PostgresqlStatusInfo;
use App\Livewire\Project\Database\Redis\StatusInfo as RedisStatusInfo;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMongodb;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->server->generateCaCertificate();
});

dataset('ssl-regenerate-databases', [
    'postgresql' => [
        StandalonePostgresql::class,
        PostgresqlStatusInfo::class,
        ['postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'postgres', 'image' => 'postgres:16-alpine'],
        '/var/lib/postgresql/certs',
        ['server.crt', 'server.key'],
    ],
    'redis' => [
        StandaloneRedis::class,
        RedisStatusInfo::class,
        ['image' => 'redis:7.2'],
        '/etc/redis/certs',
        ['server.crt', 'server.key'],
    ],
    'mongodb' => [
        StandaloneMongodb::class,
        MongodbStatusInfo::class,
        ['mongo_initdb_root_username' => 'root', 'mongo_initdb_root_password' => 'password', 'image' => 'mongo:7'],
        '/etc/mongo/certs',
        ['server.pem'],
    ],
]);

function createSslDatabase(object $test, string $modelClass, array $attributes, string $mountPath, array $expectedFiles): mixed
{
    $database = $modelClass::create([
        'name' => 'ssl-regenerate-test',
        'status' => 'exited:unhealthy',
        'enable_ssl' => true,
        'environment_id' => $test->environment->id,
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
        ...$attributes,
    ]);

    $caCert = $test->server->sslCertificates()->where('is_ca_certificate', true)->firstOrFail();

    SslHelper::generateSslCertificate(
        commonName: $database->uuid,
        resourceType: $database->getMorphClass(),
        resourceId: $database->id,
        serverId: $test->server->id,
        caCert: $caCert->ssl_certificate,
        caKey: $caCert->ssl_private_key,
        configurationDir: '/data/coolify/databases/'.$database->uuid,
        mountPath: $mountPath,
        isPemKeyFileRequired: $expectedFiles === ['server.pem'],
    );

    return $database;
}

function mountedSslFiles(mixed $database, string $mountPath): array
{
    return $database->fileStorages()
        ->where('mount_path', 'like', $mountPath.'/%')
        ->pluck('mount_path')
        ->sort()
        ->values()
        ->all();
}

it('regenerates the SSL certificate files that the database engine expects', function (
    string $modelClass,
    string $componentClass,
    array $attributes,
    string $mountPath,
    array $expectedFiles,
) {
    $database = createSslDatabase($this, $modelClass, $attributes, $mountPath, $expectedFiles);

    Livewire::test($componentClass, ['database' => $database])
        ->call('regenerateSslCertificate')
        ->assertDispatched('success');

    expect(mountedSslFiles($database, $mountPath))
        ->toBe(array_map(fn (string $file) => $mountPath.'/'.$file, $expectedFiles));
})->with('ssl-regenerate-databases');

it('renews the SSL certificate files that the database engine expects', function (
    string $modelClass,
    string $componentClass,
    array $attributes,
    string $mountPath,
    array $expectedFiles,
) {
    $database = createSslDatabase($this, $modelClass, $attributes, $mountPath, $expectedFiles);
    $database->sslCertificates()->update(['valid_until' => now()->addDays(3)]);

    (new RegenerateSslCertJob(server_id: $this->server->id))->handle();

    expect($database->sslCertificates()->first()->valid_until->isAfter(now()->addDays(14)))->toBeTrue()
        ->and(mountedSslFiles($database, $mountPath))
        ->toBe(array_map(fn (string $file) => $mountPath.'/'.$file, $expectedFiles));
})->with('ssl-regenerate-databases');
