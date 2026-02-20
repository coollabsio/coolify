<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectMemberTest extends TestCase
{
    use RefreshDatabase;

    protected User $teamAdmin;

    protected User $projectMember;

    protected Team $team;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a team with an admin
        $this->teamAdmin = User::factory()->create();
        $this->team = Team::factory()->create();
        $this->teamAdmin->teams()->attach($this->team, ['role' => 'admin']);

        // Create a project
        $this->project = Project::factory()->create(['team_id' => $this->team->id]);

        // Create a project-specific member
        $this->projectMember = User::factory()->create();
        $this->project->members()->attach($this->projectMember, [
            'role' => 'member',
            'can_create_resources' => false,
        ]);
    }

    public function test_team_admin_can_add_project_member()
    {
        $newUser = User::factory()->create();

        $this->actingAs($this->teamAdmin);

        $response = $this->post(route('project.members.index', ['project_uuid' => $this->project->uuid]), [
            'email' => $newUser->email,
            'can_create_resources' => true,
        ]);

        $this->assertTrue($this->project->hasMember($newUser));
        $this->assertTrue($this->project->canMemberCreateResources($newUser));
    }

    public function test_project_member_can_only_access_assigned_project()
    {
        $this->actingAs($this->projectMember);

        // Should be able to access assigned project
        $this->assertTrue($this->projectMember->canAccessProject($this->project));

        // Create another project in the same team
        $otherProject = Project::factory()->create(['team_id' => $this->team->id]);

        // Should NOT be able to access other project
        $this->assertFalse($this->projectMember->canAccessProject($otherProject));
    }

    public function test_project_member_cannot_access_team_resources()
    {
        $this->actingAs($this->projectMember);

        // Project member should not be a team member
        $this->assertFalse($this->projectMember->teams->contains('id', $this->team->id));

        // Project member should not be able to view team settings
        $response = $this->get(route('team.index'));
        $response->assertStatus(403);
    }

    public function test_project_member_with_deploy_permission_can_create_resources()
    {
        // Update project member to allow resource creation
        $this->project->members()->updateExistingPivot($this->projectMember->id, [
            'can_create_resources' => true,
        ]);

        $this->actingAs($this->projectMember);

        $this->assertTrue($this->project->canMemberCreateResources($this->projectMember));
    }

    public function test_project_member_without_deploy_permission_cannot_create_resources()
    {
        $this->actingAs($this->projectMember);

        $this->assertFalse($this->project->canMemberCreateResources($this->projectMember));
    }

    public function test_team_admin_can_remove_project_member()
    {
        $this->actingAs($this->teamAdmin);

        $this->project->members()->detach($this->projectMember->id);

        $this->assertFalse($this->project->hasMember($this->projectMember));
    }

    public function test_project_member_can_view_project()
    {
        $this->actingAs($this->projectMember);

        $response = $this->get(route('project.show', ['project_uuid' => $this->project->uuid]));
        $response->assertStatus(200);
    }

    public function test_project_member_cannot_update_project_settings()
    {
        $this->actingAs($this->projectMember);

        $this->assertFalse($this->projectMember->can('update', $this->project));
    }

    public function test_project_member_cannot_delete_project()
    {
        $this->actingAs($this->projectMember);

        $this->assertFalse($this->projectMember->can('delete', $this->project));
    }

    public function test_project_member_cannot_manage_project_members()
    {
        $this->actingAs($this->projectMember);

        $this->assertFalse($this->projectMember->can('manageMembers', $this->project));
    }

    public function test_team_member_can_access_all_projects()
    {
        $teamMember = User::factory()->create();
        $teamMember->teams()->attach($this->team, ['role' => 'member']);

        $this->actingAs($teamMember);

        // Team member should be able to access any project in their team
        $this->assertTrue($teamMember->canAccessProject($this->project));
    }

    public function test_api_can_get_project_members()
    {
        $this->actingAs($this->teamAdmin);

        $token = $this->teamAdmin->createToken('test', ['*'], null);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])->get('/api/v1/projects/'.$this->project->uuid.'/members');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
    }

    public function test_api_can_add_project_member()
    {
        $this->actingAs($this->teamAdmin);

        $newUser = User::factory()->create();
        $token = $this->teamAdmin->createToken('test', ['*'], null);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])->post('/api/v1/projects/'.$this->project->uuid.'/members', [
            'user_id' => $newUser->id,
            'can_create_resources' => true,
        ]);

        $response->assertStatus(201);
        $this->assertTrue($this->project->hasMember($newUser));
    }

    public function test_api_can_update_project_member()
    {
        $this->actingAs($this->teamAdmin);

        $token = $this->teamAdmin->createToken('test', ['*'], null);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])->patch('/api/v1/projects/'.$this->project->uuid.'/members/'.$this->projectMember->id, [
            'can_create_resources' => true,
        ]);

        $response->assertStatus(200);
        $this->assertTrue($this->project->canMemberCreateResources($this->projectMember));
    }

    public function test_api_can_remove_project_member()
    {
        $this->actingAs($this->teamAdmin);

        $token = $this->teamAdmin->createToken('test', ['*'], null);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])->delete('/api/v1/projects/'.$this->project->uuid.'/members/'.$this->projectMember->id);

        $response->assertStatus(200);
        $this->assertFalse($this->project->hasMember($this->projectMember));
    }

    public function test_cannot_add_team_member_as_project_member()
    {
        $teamMember = User::factory()->create();
        $teamMember->teams()->attach($this->team, ['role' => 'member']);

        $this->actingAs($this->teamAdmin);

        $token = $this->teamAdmin->createToken('test', ['*'], null);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ])->post('/api/v1/projects/'.$this->project->uuid.'/members', [
            'user_id' => $teamMember->id,
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'User is already a team member. Team members have access to all projects.']);
    }

    public function test_project_member_is_removed_when_project_deleted()
    {
        $this->actingAs($this->teamAdmin);

        $this->assertTrue($this->project->hasMember($this->projectMember));

        $this->project->delete();

        // Check that the project_user pivot record is gone
        $this->assertDatabaseMissing('project_user', [
            'project_id' => $this->project->id,
            'user_id' => $this->projectMember->id,
        ]);
    }
}
