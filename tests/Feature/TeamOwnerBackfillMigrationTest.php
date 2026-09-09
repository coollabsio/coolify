<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it promotes the first member only when a team has no owner', function () {
    $teamWithoutOwner = Team::factory()->create(['personal_team' => false]);
    $firstMember = User::factory()->create();
    $firstAdmin = User::factory()->create();
    $teamWithoutOwner->members()->attach($firstMember, [
        'role' => 'member',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);
    $teamWithoutOwner->members()->attach($firstAdmin, [
        'role' => 'admin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $teamWithoutAdmin = Team::factory()->create(['personal_team' => false]);
    $fallbackMember = User::factory()->create();
    $teamWithoutAdmin->members()->attach($fallbackMember, ['role' => 'member']);

    $teamWithOwner = Team::factory()->create(['personal_team' => false]);
    $existingOwner = User::factory()->create();
    $existingAdmin = User::factory()->create();
    $teamWithOwner->members()->attach($existingOwner, ['role' => 'owner']);
    $teamWithOwner->members()->attach($existingAdmin, ['role' => 'admin']);

    $migration = require database_path('migrations/2026_08_20_070831_promote_first_team_member_when_team_has_no_owner.php');
    $migration->up();

    expect($teamWithoutOwner->members()->find($firstMember->id)->pivot->role)->toBe('member')
        ->and($teamWithoutOwner->members()->find($firstAdmin->id)->pivot->role)->toBe('owner')
        ->and($teamWithoutAdmin->members()->find($fallbackMember->id)->pivot->role)->toBe('owner')
        ->and($teamWithOwner->members()->find($existingOwner->id)->pivot->role)->toBe('owner')
        ->and($teamWithOwner->members()->find($existingAdmin->id)->pivot->role)->toBe('admin');
});
