<?php

use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;

beforeEach(function () {
    // Create partial mock of User that uses the real traits
    $this->user = Mockery::mock(User::class)->makePartial();
    $this->project = Mockery::mock(Project::class)->makePartial();
    $this->project->id = 1;
});

afterEach(function () {
    Mockery::close();
});

describe('Role-based bypass', function () {
    it('allows owner to perform any action', function () {
        $this->user->shouldReceive('currentTeam->id')->andReturn(1);
        $this->user->shouldReceive('roleInTeam')->with(1)->andReturn(Role::OWNER->value);
        $this->user->is_global_admin = false;

        expect($this->user->canPerform('deploy', $this->project))->toBeTrue();
        expect($this->user->canPerform('delete', $this->project))->toBeTrue();
        expect($this->user->canPerform('view', $this->project))->toBeTrue();
    });

    it('allows admin to perform most actions', function () {
        $this->user->shouldReceive('currentTeam->id')->andReturn(1);
        $this->user->shouldReceive('roleInTeam')->with(1)->andReturn(Role::ADMIN->value);
        $this->user->is_global_admin = false;

        expect($this->user->canPerform('deploy', $this->project))->toBeTrue();
        expect($this->user->canPerform('delete', $this->project))->toBeTrue();
        expect($this->user->canPerform('view', $this->project))->toBeTrue();
    });

    it('prevents admin from owner-only actions', function () {
        $this->user->shouldReceive('currentTeam->id')->andReturn(1);
        $this->user->shouldReceive('roleInTeam')->with(1)->andReturn(Role::ADMIN->value);
        $this->user->is_global_admin = false;

        expect($this->user->canPerform('delete_team', $this->project))->toBeFalse();
        expect($this->user->canPerform('transfer_ownership', $this->project))->toBeFalse();
        expect($this->user->canPerform('promote_to_owner', $this->project))->toBeFalse();
    });

    it('allows global admin to perform any action', function () {
        $this->user->shouldReceive('currentTeam->id')->andReturn(1);
        $this->user->is_global_admin = true;

        expect($this->user->canPerform('deploy', $this->project))->toBeTrue();
        expect($this->user->canPerform('delete_team', $this->project))->toBeTrue();
    });
});

describe('Member permission checking (granular disabled)', function () {
    beforeEach(function () {
        // Ensure granular permissions are disabled
        config(['constants.features.granular_permissions' => false]);
    });

    it('allows member full access when granular permissions disabled', function () {
        $this->user->shouldReceive('currentTeam->id')->andReturn(1);
        $this->user->shouldReceive('roleInTeam')->with(1)->andReturn(Role::MEMBER->value);
        $this->user->is_global_admin = false;

        expect($this->user->canPerform('deploy', $this->project))->toBeTrue();
        expect($this->user->canPerform('view', $this->project))->toBeTrue();
    });
});

describe('Action to permission mapping', function () {
    it('maps view actions to view permission', function () {
        $user = new class extends User
        {
            use \App\Traits\ChecksPermissions;

            public function testMapAction(string $action): string
            {
                return $this->mapActionToPermission($action);
            }
        };

        expect($user->testMapAction('view'))->toBe('view');
        expect($user->testMapAction('read'))->toBe('view');
        expect($user->testMapAction('show'))->toBe('view');
        expect($user->testMapAction('index'))->toBe('view');
        expect($user->testMapAction('list'))->toBe('view');
    });

    it('maps deploy actions to deploy permission', function () {
        $user = new class extends User
        {
            use \App\Traits\ChecksPermissions;

            public function testMapAction(string $action): string
            {
                return $this->mapActionToPermission($action);
            }
        };

        expect($user->testMapAction('deploy'))->toBe('deploy');
        expect($user->testMapAction('redeploy'))->toBe('deploy');
        expect($user->testMapAction('restart'))->toBe('deploy');
        expect($user->testMapAction('start'))->toBe('deploy');
        expect($user->testMapAction('stop'))->toBe('deploy');
    });

    it('maps manage actions to manage permission', function () {
        $user = new class extends User
        {
            use \App\Traits\ChecksPermissions;

            public function testMapAction(string $action): string
            {
                return $this->mapActionToPermission($action);
            }
        };

        expect($user->testMapAction('update'))->toBe('manage');
        expect($user->testMapAction('edit'))->toBe('manage');
        expect($user->testMapAction('configure'))->toBe('manage');
        expect($user->testMapAction('create'))->toBe('manage');
    });

    it('maps delete actions to delete permission', function () {
        $user = new class extends User
        {
            use \App\Traits\ChecksPermissions;

            public function testMapAction(string $action): string
            {
                return $this->mapActionToPermission($action);
            }
        };

        expect($user->testMapAction('delete'))->toBe('delete');
        expect($user->testMapAction('destroy'))->toBe('delete');
        expect($user->testMapAction('remove'))->toBe('delete');
        expect($user->testMapAction('force_delete'))->toBe('delete');
    });

    it('defaults unknown actions to view permission', function () {
        $user = new class extends User
        {
            use \App\Traits\ChecksPermissions;

            public function testMapAction(string $action): string
            {
                return $this->mapActionToPermission($action);
            }
        };

        expect($user->testMapAction('unknown_action'))->toBe('view');
        expect($user->testMapAction('custom_action'))->toBe('view');
    });
});

describe('No team context', function () {
    it('denies access when no team context', function () {
        $this->user->shouldReceive('currentTeam')->andReturnNull();
        $this->user->is_global_admin = false;

        expect($this->user->canPerform('view', $this->project))->toBeFalse();
    });

    it('denies access when user not in team', function () {
        $this->user->shouldReceive('currentTeam->id')->andReturn(1);
        $this->user->shouldReceive('roleInTeam')->with(1)->andReturnNull();
        $this->user->is_global_admin = false;

        expect($this->user->canPerform('view', $this->project))->toBeFalse();
    });
});
