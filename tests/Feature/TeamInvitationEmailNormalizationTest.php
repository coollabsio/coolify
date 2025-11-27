<?php

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('team invitation normalizes email to lowercase', function () {
    // Create a team
    $team = Team::factory()->create();

    // Create invitation with mixed case email
    $invitation = TeamInvitation::create([
        'team_id' => $team->id,
        'uuid' => 'test-uuid-123',
        'email' => 'Test@Example.com', // Mixed case
        'role' => 'member',
        'link' => 'https://example.com/invite/test-uuid-123',
        'via' => 'link',
    ]);

    // Verify email was normalized to lowercase
    expect($invitation->email)->toBe('test@example.com');
});

test('team invitation works with existing user email', function () {
    // Create a team
    $team = Team::factory()->create();

    // Create a user with lowercase email
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    // Create invitation with mixed case email
    $invitation = TeamInvitation::create([
        'team_id' => $team->id,
        'uuid' => 'test-uuid-123',
        'email' => 'Test@Example.com', // Mixed case
        'role' => 'member',
        'link' => 'https://example.com/invite/test-uuid-123',
        'via' => 'link',
    ]);

    // Verify the invitation email matches the user email (both normalized)
    expect($invitation->email)->toBe($user->email);

    // Verify user lookup works
    $foundUser = User::whereEmail($invitation->email)->first();
    expect($foundUser)->not->toBeNull();
    expect($foundUser->id)->toBe($user->id);
});
