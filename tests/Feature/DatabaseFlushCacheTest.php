<?php

use App\Actions\Database\FlushCacheDatabase;
use App\Livewire\Project\Database\FlushCache;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();

    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
        'user' => 'deploy',
    ]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

function makeRedis(mixed $environment, mixed $destination): StandaloneRedis
{
    return StandaloneRedis::create([
        'name' => 'cache-redis',
        'image' => 'redis:7',
        'redis_password' => 'password',
        'redis_username' => 'default',
        'status' => 'running:healthy',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function makePostgres(mixed $environment, mixed $destination): StandalonePostgresql
{
    return StandalonePostgresql::create([
        'name' => 'app-postgres',
        'image' => 'postgres:16',
        'postgres_user' => 'coolify',
        'postgres_password' => 'password',
        'postgres_db' => 'coolify',
        'status' => 'running:healthy',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

test('flushes every key with the engine CLI and records an audit event', function (string $model, array $attributes, string $cli) {
    $database = $model::create([
        'name' => 'cache',
        'image' => 'redis:7',
        'status' => 'running:healthy',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        ...$attributes,
    ]);
    Process::fake(['*' => Process::result(output: 'OK')]);

    Livewire::test(FlushCache::class, ['database' => $database])
        ->call('flush')
        ->assertDispatched('success');

    Process::assertRan(fn ($process) => str_contains($process->command, "sudo docker exec {$database->uuid} {$cli} FLUSHALL ASYNC"));
    expect(AuditEvent::query()
        ->where('event', 'ui.database.flushed')
        ->where('resource_uuid', $database->uuid)
        ->exists())->toBeTrue();
})->with([
    'redis' => [StandaloneRedis::class, [], 'redis-cli'],
    'keydb' => [StandaloneKeydb::class, ['keydb_password' => 'secret'], "keydb-cli -a 'secret'"],
    'dragonfly with tls' => [StandaloneDragonfly::class, ['dragonfly_password' => 'secret', 'enable_ssl' => true], "redis-cli --tls --cacert /etc/dragonfly/certs/coolify-ca.crt --cert /etc/dragonfly/certs/server.crt --key /etc/dragonfly/certs/server.key -a 'secret'"],
]);

test('reports an error when the database does not reply OK', function () {
    $redis = makeRedis($this->environment, $this->destination);
    Process::fake(['*' => Process::result(output: 'NOAUTH Authentication required.')]);

    Livewire::test(FlushCache::class, ['database' => $redis])
        ->call('flush')
        ->assertDispatched('error', 'NOAUTH Authentication required.');

    expect(AuditEvent::query()->where('event', 'ui.database.flushed')->exists())->toBeFalse();
});

test('refuses to flush a non-cache database and does not run the action', function () {
    $postgres = makePostgres($this->environment, $this->destination);

    FlushCacheDatabase::mock()->shouldReceive('handle')->never();

    Livewire::test(FlushCache::class, ['database' => $postgres])
        ->call('flush')
        ->assertDispatched('error');

    expect(AuditEvent::query()->where('event', 'ui.database.flushed')->exists())->toBeFalse();
});

test('denies flushing to a member without manage permission', function () {
    $redis = makeRedis($this->environment, $this->destination);

    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    FlushCacheDatabase::mock()->shouldReceive('handle')->never();

    Livewire::test(FlushCache::class, ['database' => $redis])
        ->call('flush')
        ->assertDispatched('error');

    expect(AuditEvent::query()->where('event', 'ui.database.flushed')->exists())->toBeFalse();
});

test('renders the flush cache card for a cache database', function () {
    $redis = makeRedis($this->environment, $this->destination);

    Livewire::test(FlushCache::class, ['database' => $redis])
        ->assertSee('Flush cache')
        ->assertSee('FLUSHALL ASYNC');
});

test('the danger page wires the flush cache section only for cache database types', function () {
    $source = file_get_contents(resource_path('views/livewire/project/database/configuration.blade.php'));

    // The flush-cache component is rendered on the danger route, guarded to the three cache types.
    expect($source)
        ->toContain('project.database.flush-cache')
        ->toMatch('/in_array\(\$database->type\(\), \[.*standalone-redis.*standalone-keydb.*standalone-dragonfly.*\]\)[\s\S]*project\.database\.flush-cache/');

    // It must no longer live in the heading action menu.
    $heading = file_get_contents(resource_path('views/livewire/project/database/heading.blade.php'));
    expect($heading)->not->toContain('Flush cache');
});
