<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\TeamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('assigns seeded memberships without assuming normal user or personal team IDs', function () {
    InstanceSettings::create(['id' => 0]);
    $unrelatedUser = User::factory()->create();
    User::factory()->create(['id' => 0, 'email' => 'test@example.com']);
    $rootTeamMember = User::factory()->create(['id' => 10, 'email' => 'test2@example.com']);
    $otherUser = User::factory()->create(['id' => 11, 'email' => 'test3@example.com']);
    $personalTeam = $rootTeamMember->teams()->firstOrFail();

    $this->seed(TeamSeeder::class);

    expect(Team::findOrFail(0)->description)->toBe('The root team')
        ->and($rootTeamMember->teams()->whereKey(0)->exists())->toBeTrue()
        ->and($otherUser->teams()->whereKey($personalTeam->id)->firstOrFail()->pivot->role)->toBe('admin')
        ->and($otherUser->teams()->whereKey(0)->exists())->toBeFalse()
        ->and($unrelatedUser->teams()->count())->toBe(1)
        ->and($otherUser->teams()->whereKey($unrelatedUser->teams()->firstOrFail()->id)->exists())->toBeFalse();
});
