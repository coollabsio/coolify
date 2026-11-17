<?php

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
