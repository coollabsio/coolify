<?php

use App\Models\PersonalAccessToken;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PersonalAccessTokenSeeder;
use Database\Seeders\TeamSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a terminal scoped development token', function () {
    $this->seed([
        UserSeeder::class,
        TeamSeeder::class,
        PersonalAccessTokenSeeder::class,
    ]);

    $rootUser = User::find(0);
    $rootTeam = Team::find(0);

    $token = PersonalAccessToken::query()
        ->where('tokenable_type', User::class)
        ->where('tokenable_id', $rootUser->id)
        ->where('name', 'Development Terminal Token')
        ->first();

    expect($token)
        ->not->toBeNull()
        ->and($token->token)->toBe(hash('sha256', 'terminal'))
        ->and($token->abilities)->toBe(['terminal'])
        ->and($token->team_id)->toBe((string) $rootTeam->id)
        ->and($token->expires_at)->not->toBeNull();
});
