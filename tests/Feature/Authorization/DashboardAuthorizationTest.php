<?php

use App\Livewire\Dashboard;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setupDashboardUser(string $role): array
{
    $team = Team::factory()->create();

    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role]);

    return [$user, $team];
}

function createProjectForTeam(Team $team): void
{
    Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $team->id,
    ]);
}

function createPrivateKeyForTeam(Team $team): int
{
    return DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function createServerWithKeyForTeam(Team $team): void
{
    Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => createPrivateKeyForTeam($team),
    ]);
}

// The dashboard no longer has a "New Project" button; each project card exposes an
// "Add resource" shortcut gated by the createAnyResource gate instead.
test('admin sees add resource button on dashboard projects', function () {
    [$user, $team] = setupDashboardUser('admin');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    createProjectForTeam($team);

    Livewire::test(Dashboard::class)
        ->assertSee('Test Project')
        ->assertSee('Add resource to Test Project');
});

test('member does not see add resource button on dashboard projects', function () {
    [$user, $team] = setupDashboardUser('member');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    createProjectForTeam($team);

    Livewire::test(Dashboard::class)
        ->assertSee('Test Project')
        ->assertDontSee('Add resource to Test Project')
        ->assertDontSee('New Project');
});

// The "New server" button is only rendered in the empty servers state (a key exists, no servers yet).
test('admin sees add server button on dashboard', function () {
    [$user, $team] = setupDashboardUser('admin');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    createPrivateKeyForTeam($team);

    Livewire::test(Dashboard::class)
        ->assertSee('No servers yet')
        ->assertSee(route('server.create'));
});

test('member does not see add server button on dashboard', function () {
    [$user, $team] = setupDashboardUser('member');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    createPrivateKeyForTeam($team);

    Livewire::test(Dashboard::class)
        ->assertSee('No servers yet')
        ->assertDontSee(route('server.create'));
});

test('member does not see add server button on dashboard with existing servers', function () {
    [$user, $team] = setupDashboardUser('member');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    createServerWithKeyForTeam($team);

    Livewire::test(Dashboard::class)
        ->assertDontSee(route('server.create'));
});
