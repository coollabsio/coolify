<?php

use App\Enums\AccessLevel;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = $server->standaloneDockers()->firstOrFail();

    $this->shop = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'shop']);
    $this->shopProduction = Environment::factory()->create(['project_id' => $this->shop->id, 'name' => 'prod']);
    $this->blog = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'blog']);
    $this->blogStaging = Environment::factory()->create(['project_id' => $this->blog->id, 'name' => 'staging']);

    $this->shopApi = accessGrantsTestApplication($this->shopProduction, $this->destination);
    $this->blogWeb = accessGrantsTestApplication($this->blogStaging, $this->destination);
    $this->blogWorker = accessGrantsTestApplication($this->blogStaging, $this->destination);
});

function accessGrantsTestApplication(Environment $environment, $destination): Application
{
    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function accessGrantsTestMember(Team $team, ?array $access, string $role = 'member'): User
{
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role, 'access' => $access === null ? null : json_encode($access)]);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return $user->fresh();
}

it('keeps team-wide read access for members without stored grants', function () {
    $member = accessGrantsTestMember($this->team, null);

    expect(Project::query()->pluck('name')->sort()->values()->all())->toBe(['blog', 'shop'])
        ->and(Application::query()->count())->toBe(3)
        ->and($member->canAccess(AccessLevel::Read, $this->shopApi))->toBeTrue()
        ->and($member->canAccess(AccessLevel::Write, $this->shopApi))->toBeFalse();
});

it('limits a member with a project grant to that project', function () {
    $member = accessGrantsTestMember($this->team, [
        ['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read', 'deploy']],
    ]);

    expect(Project::query()->pluck('name')->all())->toBe(['shop'])
        ->and(Environment::query()->pluck('project_id')->unique()->values()->all())->toBe([$this->shop->id])
        ->and(Application::query()->pluck('id')->all())->toBe([$this->shopApi->id])
        ->and(Application::query()->where('uuid', $this->blogWeb->uuid)->first())->toBeNull()
        ->and($member->canAccess(AccessLevel::Deploy, $this->shopApi))->toBeTrue()
        ->and($member->canAccess(AccessLevel::Write, $this->shopApi))->toBeFalse()
        ->and($member->canAccess(AccessLevel::Read, $this->blogWeb))->toBeFalse();
});

it('shows only the granted resource and its parents for a resource grant', function () {
    $member = accessGrantsTestMember($this->team, [
        ['scope' => 'resource', 'uuid' => $this->blogWeb->uuid, 'levels' => ['write']],
    ]);

    expect(Project::query()->pluck('name')->all())->toBe(['blog'])
        ->and(Environment::query()->pluck('id')->all())->toBe([$this->blogStaging->id])
        ->and(Application::query()->pluck('id')->all())->toBe([$this->blogWeb->id])
        ->and($this->blogStaging->applications()->pluck('id')->all())->toBe([$this->blogWeb->id])
        ->and($member->canAccess(AccessLevel::Write, $this->blogWeb))->toBeTrue()
        ->and($member->canAccess(AccessLevel::Delete, $this->blogWeb))->toBeFalse()
        ->and($member->canAccess(AccessLevel::Read, $this->blogWorker))->toBeFalse()
        ->and($member->canAccess(AccessLevel::Read, $this->blogStaging))->toBeFalse();
});

it('gives environment grants to every resource in the environment only', function () {
    $member = accessGrantsTestMember($this->team, [
        ['scope' => 'environment', 'uuid' => $this->blogStaging->uuid, 'levels' => ['read:sensitive']],
    ]);

    expect(Application::query()->pluck('id')->sort()->values()->all())->toBe([$this->blogWeb->id, $this->blogWorker->id])
        ->and($member->canAccess(AccessLevel::ReadSensitive, $this->blogWorker))->toBeTrue()
        ->and($member->canAccess(AccessLevel::Deploy, $this->blogWorker))->toBeFalse()
        ->and($member->canAccess(AccessLevel::Read, $this->shopApi))->toBeFalse();
});

it('does not filter admins, even with stored grants', function () {
    $admin = accessGrantsTestMember($this->team, [
        ['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']],
    ], 'admin');

    expect(Project::query()->count())->toBe(2)
        ->and($admin->canAccess(AccessLevel::Delete, $this->blogWeb))->toBeTrue();
});

it('ignores grants that point to another team', function () {
    $otherTeam = Team::factory()->create();
    $foreignProject = Project::factory()->create(['team_id' => $otherTeam->id]);

    accessGrantsTestMember($this->team, [
        ['scope' => 'project', 'uuid' => $foreignProject->uuid, 'levels' => ['root']],
    ]);

    expect(Project::query()->withoutGlobalScopes()->where('team_id', $this->team->id)->count())->toBe(2)
        ->and(Project::query()->count())->toBe(0);
});

it('checks a resource with the grants of its own team after the session team changes', function () {
    $member = accessGrantsTestMember($this->team, [
        ['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['write']],
    ]);
    $ownTeam = Team::factory()->create();
    $member->teams()->attach($ownTeam, ['role' => 'owner']);
    $member = $member->fresh();
    session(['currentTeam' => $ownTeam]);

    expect($member->canAccess(AccessLevel::Write, $this->shopApi))->toBeTrue()
        ->and($member->canAccess(AccessLevel::Write, $this->blogWeb))->toBeFalse();
});

it('lets a member open the parents of a granted resource but not other projects', function () {
    $member = accessGrantsTestMember($this->team, [
        ['scope' => 'resource', 'uuid' => $this->blogWeb->uuid, 'levels' => ['read']],
    ]);

    expect($member->can('view', $this->blog))->toBeTrue()
        ->and($member->can('view', $this->blogStaging))->toBeTrue()
        ->and($member->can('view', $this->shop))->toBeFalse()
        ->and($member->can('view', $this->shopProduction))->toBeFalse()
        ->and($member->can('update', $this->blogStaging))->toBeFalse();
});
