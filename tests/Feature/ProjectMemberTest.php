<?php

use App\Enums\ProjectMemberRole;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\ProjectMember;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->teamMember = User::factory()->create();

    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);
    $this->team->members()->attach($this->teamMember->id, ['role' => 'member']);

    $this->project = Project::create([
        'name' => 'Test Project',
        'team_id' => $this->team->id,
    ]);

    // Create a project-specific member
    $this->projectUser = User::factory()->create();
    $this->team->members()->attach($this->projectUser->id, ['role' => 'member']);
    $this->projectMember = ProjectMember::create([
        'user_id' => $this->projectUser->id,
        'project_id' => $this->project->id,
        'role' => 'viewer',
        'invited_by' => $this->owner->id,
        'accepted_at' => now(),
    ]);
});

describe('ProjectMember model', function () {
    test('can create a project member with viewer role', function () {
        expect($this->projectMember)->not->toBeNull();
        expect($this->projectMember->role)->toBe(ProjectMemberRole::Viewer);
        expect($this->projectMember->user_id)->toBe($this->projectUser->id);
        expect($this->projectMember->project_id)->toBe($this->project->id);
    });

    test('viewer cannot deploy', function () {
        expect($this->projectMember->canDeploy())->toBeFalse();
    });

    test('viewer cannot manage', function () {
        expect($this->projectMember->canManage())->toBeFalse();
    });

    test('deployer can deploy but not manage', function () {
        $this->projectMember->update(['role' => 'deployer']);
        $this->projectMember->refresh();
        expect($this->projectMember->canDeploy())->toBeTrue();
        expect($this->projectMember->canManage())->toBeFalse();
    });

    test('manager can deploy and manage', function () {
        $this->projectMember->update(['role' => 'manager']);
        $this->projectMember->refresh();
        expect($this->projectMember->canDeploy())->toBeTrue();
        expect($this->projectMember->canManage())->toBeTrue();
    });
});

describe('Project model relationships', function () {
    test('project has project members', function () {
        expect($this->project->projectMembers()->count())->toBe(1);
    });

    test('project can check if user is project member', function () {
        expect($this->project->isProjectMember($this->projectUser))->toBeTrue();
        expect($this->project->isProjectMember($this->owner))->toBeFalse();
    });

    test('project can get project member record', function () {
        $member = $this->project->getProjectMember($this->projectUser);
        expect($member)->not->toBeNull();
        expect($member->id)->toBe($this->projectMember->id);
    });

    test('team owner can access project', function () {
        expect($this->project->userCanAccess($this->owner))->toBeTrue();
    });

    test('project member can access their project', function () {
        expect($this->project->userCanAccess($this->projectUser))->toBeTrue();
    });

    test('outsider cannot access project', function () {
        $outsider = User::factory()->create();
        expect($this->project->userCanAccess($outsider))->toBeFalse();
    });
});

describe('ProjectMemberRole enum', function () {
    test('roles have correct ranks', function () {
        expect(ProjectMemberRole::Viewer->rank())->toBe(1);
        expect(ProjectMemberRole::Deployer->rank())->toBe(2);
        expect(ProjectMemberRole::Manager->rank())->toBe(3);
    });

    test('viewer role cannot deploy', function () {
        expect(ProjectMemberRole::Viewer->canDeploy())->toBeFalse();
    });

    test('deployer role can deploy', function () {
        expect(ProjectMemberRole::Deployer->canDeploy())->toBeTrue();
    });

    test('manager role can manage', function () {
        expect(ProjectMemberRole::Manager->canManage())->toBeTrue();
    });

    test('deployer role cannot manage', function () {
        expect(ProjectMemberRole::Deployer->canManage())->toBeFalse();
    });
});

describe('Permission checks', function () {
    test('viewer has view permission', function () {
        expect($this->projectMember->hasPermission('view'))->toBeTrue();
    });

    test('viewer does not have deploy permission', function () {
        expect($this->projectMember->hasPermission('deploy'))->toBeFalse();
    });

    test('viewer does not have manage permission', function () {
        expect($this->projectMember->hasPermission('manage'))->toBeFalse();
    });

    test('custom permissions override role defaults', function () {
        $this->projectMember->update(['permissions' => ['deploy' => true]]);
        $this->projectMember->refresh();
        expect($this->projectMember->hasPermission('deploy'))->toBeTrue();
    });
});

describe('Project deletion cleans up members', function () {
    test('deleting project removes project members', function () {
        expect(ProjectMember::where('project_id', $this->project->id)->count())->toBe(1);

        // Need to remove environments/settings first since project uses them
        $this->project->environments()->each(function ($env) {
            $env->delete();
        });
        $this->project->settings()->delete();
        $this->project->delete();

        expect(ProjectMember::where('project_id', $this->project->id)->count())->toBe(0);
    });
});

describe('ProjectInvitation model', function () {
    test('can create a project invitation', function () {
        $invitation = ProjectInvitation::create([
            'project_id' => $this->project->id,
            'team_id' => $this->team->id,
            'uuid' => 'test-uuid-123',
            'email' => 'newuser@example.com',
            'role' => 'deployer',
            'link' => 'http://localhost/project-invitation/test-uuid-123',
            'via' => 'link',
            'invited_by' => $this->owner->id,
        ]);

        expect($invitation)->not->toBeNull();
        expect($invitation->email)->toBe('newuser@example.com');
        expect($invitation->role)->toBe('deployer');
    });

    test('email is stored lowercase', function () {
        $invitation = ProjectInvitation::create([
            'project_id' => $this->project->id,
            'team_id' => $this->team->id,
            'uuid' => 'test-uuid-456',
            'email' => 'UPPER@EXAMPLE.COM',
            'role' => 'viewer',
            'link' => 'http://localhost/project-invitation/test-uuid-456',
            'via' => 'email',
            'invited_by' => $this->owner->id,
        ]);

        expect($invitation->email)->toBe('upper@example.com');
    });
});

describe('User model project relationships', function () {
    test('user has project memberships', function () {
        expect($this->projectUser->projectMemberships()->count())->toBe(1);
    });

    test('user can get accessible projects', function () {
        expect($this->projectUser->accessibleProjects()->count())->toBe(1);
    });

    test('user can check project member status', function () {
        expect($this->projectUser->isProjectMember())->toBeTrue();
        expect($this->owner->isProjectMember())->toBeFalse();
    });

    test('user can get project role', function () {
        expect($this->projectUser->projectRole($this->project->id))->toBe('viewer');
    });
});
