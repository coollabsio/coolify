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

function createServerWithKeyForTeam(Team $team): void
{
    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $keyId,
    ]);
}

test('admin sees add project button on dashboard', function () {
    [$user, $team] = setupDashboardUser('admin');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    createProjectForTeam($team);

    Livewire::test(Dashboard::class)
        ->assertSee('New Project');
});

test('member does not see add project button on dashboard', function () {
    [$user, $team] = setupDashboardUser('member');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    createProjectForTeam($team);

    Livewire::test(Dashboard::class)
        ->assertDontSee('New Project');
});

test('admin sees add server button on dashboard', function () {
    [$user, $team] = setupDashboardUser('admin');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    createServerWithKeyForTeam($team);

    Livewire::test(Dashboard::class)
        ->assertSee(route('server.create'));
});

test('member does not see add server button on dashboard', function () {
    [$user, $team] = setupDashboardUser('member');

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    createServerWithKeyForTeam($team);

    Livewire::test(Dashboard::class)
        ->assertDontSee(route('server.create'));
});
