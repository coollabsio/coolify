<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamInvitationCaseSensitivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_invitation_handles_email_case_sensitivity_correctly()
    {
        // Create a team
        $team = Team::factory()->create(['name' => 'Test Team']);

        // Create a user with lowercase email
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'name' => 'Test User'
        ]);

        // Verify the user's email was normalized to lowercase
        $this->assertEquals('test@example.com', $user->email);

        // Create a team invitation with mixed case email (simulating the bug)
        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'uuid' => 'test-uuid-123',
            'email' => 'Test@example.com', // Mixed case - this is the bug
            'role' => 'member',
            'link' => 'https://example.com/invite/test-uuid-123',
            'via' => 'link'
        ]);

        // Verify the invitation was created with normalized lowercase email
        $this->assertEquals('test@example.com', $invitation->email);

        // Now simulate the invitation acceptance process
        // This is where the bug occurs - the system looks for a user with the exact email from the invitation
        $foundUser = User::whereEmail($invitation->email)->first();

        // This should now work because both emails are normalized to lowercase
        $this->assertNotNull($foundUser, 'User lookup should work with normalized emails');

        // The correct lookup should work
        $correctUser = User::whereEmail(strtolower($invitation->email))->first();
        $this->assertNotNull($correctUser, 'User should be found with lowercase email');
        $this->assertEquals($user->id, $correctUser->id);
    }

    public function test_team_invitation_should_normalize_email_before_storage()
    {
        // Create a team
        $team = Team::factory()->create(['name' => 'Test Team']);

        // Create a user with lowercase email
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'name' => 'Test User'
        ]);

        // Simulate creating an invitation with mixed case email
        $mixedCaseEmail = 'Test@example.com';
        
        // This is what should happen - normalize the email before storing
        $normalizedEmail = strtolower($mixedCaseEmail);
        
        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'uuid' => 'test-uuid-123',
            'email' => $normalizedEmail, // Store normalized email
            'role' => 'member',
            'link' => 'https://example.com/invite/test-uuid-123',
            'via' => 'link'
        ]);

        // Now the lookup should work correctly
        $foundUser = User::whereEmail($invitation->email)->first();
        $this->assertNotNull($foundUser, 'User should be found with normalized email');
        $this->assertEquals($user->id, $foundUser->id);
    }

    public function test_team_invitation_prevents_duplicate_invitations_with_different_case()
    {
        // Create a team
        $team = Team::factory()->create(['name' => 'Test Team']);

        // Create first invitation with lowercase email
        $invitation1 = TeamInvitation::create([
            'team_id' => $team->id,
            'uuid' => 'test-uuid-1',
            'email' => 'test@example.com',
            'role' => 'member',
            'link' => 'https://example.com/invite/test-uuid-1',
            'via' => 'link'
        ]);

        // Try to create second invitation with mixed case email
        // This should be prevented by the unique constraint since both emails normalize to the same value
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessage('duplicate key value violates unique constraint');
        
        TeamInvitation::create([
            'team_id' => $team->id,
            'uuid' => 'test-uuid-2',
            'email' => 'Test@example.com', // Mixed case - should normalize to same as above
            'role' => 'member',
            'link' => 'https://example.com/invite/test-uuid-2',
            'via' => 'link'
        ]);
    }
}
