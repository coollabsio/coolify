<?php

use App\Livewire\Project\Resource\Create;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);
    $this->user->teams()->attach($this->otherTeam, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    Event::listen(RouteMatched::class, function (RouteMatched $event) {
        $event->route->setParameter('project_uuid', $this->project->uuid);
        $event->route->setParameter('environment_uuid', $this->environment->uuid);
    });
});

test('pending creation denies revoked resource team access after switching teams', function (string $role) {
    $component = Livewire::withQueryParams(['type' => 'redis', 'destination' => $this->destination->uuid, 'database_image' => 'valkey/valkey:9-alpine'])->test(Create::class);
    if ($role === 'removed') {
        $this->user->teams()->detach($this->team);
    } else {
        $this->user->teams()->updateExistingPivot($this->team->id, ['role' => $role]);
    }
    $this->user->unsetRelation('teams');
    session(['currentTeam' => $this->otherTeam]);
    $component->call('createResource')->assertForbidden();
    expect(StandaloneRedis::count())->toBe(0);
})->with(['member', 'removed']);

test('pending creation still allows the resource team admin after a team switch', function () {
    $component = Livewire::withQueryParams(['type' => 'redis', 'destination' => $this->destination->uuid, 'database_image' => 'valkey/valkey:9-alpine'])->test(Create::class);
    session(['currentTeam' => $this->otherTeam]);
    $component->call('createResource')->assertRedirect();
    expect(StandaloneRedis::where('environment_id', $this->environment->id)->count())->toBe(1);
});

test('pending creation rejects a destination transferred to another team', function () {
    $component = Livewire::withQueryParams(['type' => 'redis', 'destination' => $this->destination->uuid, 'database_image' => 'valkey/valkey:9-alpine'])->test(Create::class);
    $this->server->update(['team_id' => $this->otherTeam->id]);
    Server::flushIdentityMap();
    $component->call('createResource')->assertForbidden();
    expect(StandaloneRedis::count())->toBe(0);
});

function pendingCreationMemberWithAccess(Team $team, array $access): User
{
    $member = User::factory()->create();
    $member->teams()->attach($team, ['role' => 'member', 'access' => json_encode($access)]);
    test()->actingAs($member);
    session(['currentTeam' => $team]);

    return $member;
}

test('pending creation allows a member with a write grant on the environment only', function () {
    pendingCreationMemberWithAccess($this->team, [
        ['scope' => 'environment', 'uuid' => $this->environment->uuid, 'levels' => ['write']],
    ]);

    Livewire::withQueryParams(['type' => 'redis', 'destination' => $this->destination->uuid, 'database_image' => 'valkey/valkey:9-alpine'])
        ->test(Create::class)
        ->call('createResource')
        ->assertRedirect();

    expect(StandaloneRedis::where('environment_id', $this->environment->id)->count())->toBe(1);
});

test('pending creation denies a member with only a read grant on the environment', function () {
    pendingCreationMemberWithAccess($this->team, [
        ['scope' => 'environment', 'uuid' => $this->environment->uuid, 'levels' => ['read']],
    ]);

    Livewire::withQueryParams(['type' => 'redis', 'destination' => $this->destination->uuid, 'database_image' => 'valkey/valkey:9-alpine'])
        ->test(Create::class)
        ->assertForbidden();

    expect(StandaloneRedis::count())->toBe(0);
});

test('pending creation denies an environment write grant that was revoked after mount', function () {
    $member = pendingCreationMemberWithAccess($this->team, [
        ['scope' => 'environment', 'uuid' => $this->environment->uuid, 'levels' => ['write']],
    ]);
    $component = Livewire::withQueryParams(['type' => 'redis', 'destination' => $this->destination->uuid, 'database_image' => 'valkey/valkey:9-alpine'])->test(Create::class);

    $member->teams()->updateExistingPivot($this->team->id, ['access' => json_encode([
        ['scope' => 'environment', 'uuid' => $this->environment->uuid, 'levels' => ['read']],
    ])]);
    $member->unsetRelation('teams');

    $component->call('createResource')->assertForbidden();
    expect(StandaloneRedis::count())->toBe(0);
});
