<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamInvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_flow_works_with_mixed_case_email()
    {
        // Create a team
        $team = Team::factory()->create(['name' => 'Test Team']);

        // Create a user with lowercase email
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'name' => 'Test User'
        ]);

        // Create an invitation with mixed case email (simulating the bug scenario)
        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'uuid' => 'test-uuid-123',
            'email' => 'Test@example.com', // Mixed case
            'role' => 'member',
            'link' => 'https://example.com/invite/test-uuid-123',
            'via' => 'link'
        ]);

        // Verify the invitation was normalized to lowercase
        $this->assertEquals('test@example.com', $invitation->email);

        // Simulate the invitation acceptance process
        // This should now work because both emails are normalized
        $foundUser = User::whereEmail($invitation->email)->first();
        $this->assertNotNull($foundUser, 'User should be found with normalized email');
        $this->assertEquals($user->id, $foundUser->id);

        // Simulate adding the user to the team
        $user->teams()->attach($team->id, ['role' => $invitation->role]);
        
        // Verify the user is now a member of the team
        $this->assertTrue($user->teams()->where('team_id', $team->id)->exists());
    }

    public function test_invitation_prevents_duplicate_with_different_case()
    {
        // Create a team
        $team = Team::factory()->create(['name' => 'Test Team']);

        // Create first invitation with lowercase email
        TeamInvitation::create([
            'team_id' => $team->id,
            'uuid' => 'test-uuid-1',
            'email' => 'test@example.com',
            'role' => 'member',
            'link' => 'https://example.com/invite/test-uuid-1',
            'via' => 'link'
        ]);

        // Try to create second invitation with mixed case email
        // This should fail due to unique constraint
        $this->expectException(\Illuminate\Database\QueryException::class);
        
        TeamInvitation::create([
            'team_id' => $team->id,
            'uuid' => 'test-uuid-2',
            'email' => 'Test@example.com', // Mixed case
            'role' => 'member',
            'link' => 'https://example.com/invite/test-uuid-2',
            'via' => 'link'
        ]);
    }

    public function test_invitation_works_with_existing_user_different_case()
    {
        // Create a team
        $team = Team::factory()->create(['name' => 'Test Team']);

        // Create a user with lowercase email
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'name' => 'Test User'
        ]);

        // Create an invitation with mixed case email
        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'uuid' => 'test-uuid-123',
            'email' => 'Test@example.com', // Mixed case
            'role' => 'member',
            'link' => 'https://example.com/invite/test-uuid-123',
            'via' => 'link'
        ]);

        // The invitation should be normalized
        $this->assertEquals('test@example.com', $invitation->email);

        // The user lookup should work
        $foundUser = User::whereEmail($invitation->email)->first();
        $this->assertNotNull($foundUser);
        $this->assertEquals($user->id, $foundUser->id);
    }
}
