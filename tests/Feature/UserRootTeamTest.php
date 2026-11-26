<?php

use App\Livewire\Team\Member;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('attaches the root user as owner when reusing an existing root team', function () {
    Team::factory()->create(['id' => 0, 'name' => 'Existing Root Team']);

    $rootUser = User::factory()->create(['id' => 0]);

    expect($rootUser->teams()->whereKey(0)->first()?->pivot?->role)->toBe('owner');
});

it('promotes the root user to owner when the reused root team pivot already exists', function () {
    Team::factory()->create(['id' => 0, 'name' => 'Existing Root Team']);

    DB::table('team_user')->insert([
        'team_id' => 0,
        'user_id' => 0,
        'role' => 'member',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rootUser = User::factory()->create(['id' => 0]);

    expect($rootUser->teams()->whereKey(0)->first()?->pivot?->role)->toBe('owner');
});

it('reattaches the root user to the existing root team instead of creating a second one', function () {
    $rootUser = User::factory()->create(['id' => 0]);
    Team::find(0)->update(['name' => 'Renamed Root Team']);
    $rootUser->teams()->detach();

    $team = $rootUser->recreate_personal_team();

    expect($team->id)->toBe(0)
        ->and($team->name)->toBe('Renamed Root Team')
        ->and(Team::count())->toBe(1)
        ->and($rootUser->teams()->whereKey(0)->first()?->pivot?->role)->toBe('owner');
});

it('does not let a root team owner remove the root user from the root team', function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $rootUser = User::factory()->create(['id' => 0]);
    $rootTeam = Team::find(0);
    $owner = User::factory()->create();
    $rootTeam->members()->attach($owner->id, ['role' => 'owner']);

    $this->actingAs($owner);
    session(['currentTeam' => $rootTeam]);

    Livewire\Livewire::test(Member::class, ['member' => $rootUser])
        ->call('remove')
        ->assertDispatched('error');

    expect($rootUser->teams()->whereKey(0)->exists())->toBeTrue();
});

it('does not let a root team owner demote the root user in the root team', function (string $action) {
    InstanceSettings::forceCreate(['id' => 0]);
    $rootUser = User::factory()->create(['id' => 0]);
    $rootTeam = Team::find(0);
    $owner = User::factory()->create();
    $rootTeam->members()->attach($owner->id, ['role' => 'owner']);

    $this->actingAs($owner);
    session(['currentTeam' => $rootTeam]);

    Livewire\Livewire::test(Member::class, ['member' => $rootUser])
        ->call($action)
        ->assertDispatched('error');

    expect($rootUser->teams()->whereKey(0)->first()?->pivot?->role)->toBe('owner');
})->with(['makeAdmin', 'makeReadonly']);
