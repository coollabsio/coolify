<?php

use App\Actions\Database\FlushCacheDatabase;
use App\Livewire\Project\Database\Heading;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    $server = Server::factory()->create(['team_id' => $this->team->id]);
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

test('flushes the cache of a running redis database and records an audit event', function () {
    $redis = makeRedis($this->environment, $this->destination);

    FlushCacheDatabase::mock()->shouldReceive('handle')->once();

    Livewire::test(Heading::class, ['database' => $redis])
        ->call('flush')
        ->assertDispatched('success');

    expect(AuditEvent::query()
        ->where('event', 'ui.database.flushed')
        ->where('resource_uuid', $redis->uuid)
        ->exists())->toBeTrue();
});

test('refuses to flush a non-cache database and does not run the action', function () {
    $postgres = StandalonePostgresql::create([
        'name' => 'app-postgres',
        'image' => 'postgres:16',
        'postgres_user' => 'coolify',
        'postgres_password' => 'password',
        'postgres_db' => 'coolify',
        'status' => 'running:healthy',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    FlushCacheDatabase::mock()->shouldReceive('handle')->never();

    Livewire::test(Heading::class, ['database' => $postgres])
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

    Livewire::test(Heading::class, ['database' => $redis])
        ->call('flush')
        ->assertDispatched('error');

    expect(AuditEvent::query()->where('event', 'ui.database.flushed')->exists())->toBeFalse();
});

test('only cache databases expose the flush cache action in the heading', function () {
    $redis = makeRedis($this->environment, $this->destination);

    Livewire::test(Heading::class, ['database' => $redis])
        ->assertSee('Flush cache');

    $postgres = StandalonePostgresql::create([
        'name' => 'app-postgres',
        'image' => 'postgres:16',
        'postgres_user' => 'coolify',
        'postgres_password' => 'password',
        'postgres_db' => 'coolify',
        'status' => 'running:healthy',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Livewire::test(Heading::class, ['database' => $postgres])
        ->assertDontSee('Flush cache');
});
