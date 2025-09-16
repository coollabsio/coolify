<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamInvitationEmailNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_invitation_normalizes_email_to_lowercase()
    {
        // Create a team
        $team = Team::factory()->create();

        // Create invitation with mixed case email
        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'uuid' => 'test-uuid-123',
            'email' => 'Test@Example.com', // Mixed case
            'role' => 'member',
            'link' => 'https://example.com/invite/test-uuid-123',
            'via' => 'link'
        ]);

        // Verify email was normalized to lowercase
        $this->assertEquals('test@example.com', $invitation->email);
    }

    public function test_team_invitation_works_with_existing_user_email()
    {
        // Create a team
        $team = Team::factory()->create();

        // Create a user with lowercase email
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'name' => 'Test User'
        ]);

        // Create invitation with mixed case email
        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'uuid' => 'test-uuid-123',
            'email' => 'Test@Example.com', // Mixed case
            'role' => 'member',
            'link' => 'https://example.com/invite/test-uuid-123',
            'via' => 'link'
        ]);

        // Verify the invitation email matches the user email (both normalized)
        $this->assertEquals($user->email, $invitation->email);

        // Verify user lookup works
        $foundUser = User::whereEmail($invitation->email)->first();
        $this->assertNotNull($foundUser);
        $this->assertEquals($user->id, $foundUser->id);
    }
}
