<?php

use App\Models\ProjectUser;

describe('ProjectUser permission methods', function () {
    it('checks individual permissions correctly', function () {
        $projectUser = new ProjectUser;
        $projectUser->permissions = [
            'view' => true,
            'deploy' => true,
            'manage' => false,
            'delete' => false,
        ];

        expect($projectUser->hasPermission('view'))->toBeTrue();
        expect($projectUser->hasPermission('deploy'))->toBeTrue();
        expect($projectUser->hasPermission('manage'))->toBeFalse();
        expect($projectUser->hasPermission('delete'))->toBeFalse();
    });

    it('returns false for missing permissions', function () {
        $projectUser = new ProjectUser;
        $projectUser->permissions = [];

        expect($projectUser->hasPermission('view'))->toBeFalse();
        expect($projectUser->hasPermission('nonexistent'))->toBeFalse();
    });

    it('has convenience methods for common permissions', function () {
        $projectUser = new ProjectUser;
        $projectUser->permissions = [
            'view' => true,
            'deploy' => true,
            'manage' => false,
            'delete' => false,
        ];

        expect($projectUser->canView())->toBeTrue();
        expect($projectUser->canDeploy())->toBeTrue();
        expect($projectUser->canManage())->toBeFalse();
        expect($projectUser->canDelete())->toBeFalse();
    });

    it('grants individual permissions', function () {
        $projectUser = new ProjectUser;
        $projectUser->permissions = ['view' => true];

        $projectUser->grantPermission('deploy');

        expect($projectUser->permissions['deploy'])->toBeTrue();
        expect($projectUser->permissions['view'])->toBeTrue();
    });

    it('revokes individual permissions', function () {
        $projectUser = new ProjectUser;
        $projectUser->permissions = ['view' => true, 'deploy' => true];

        $projectUser->revokePermission('deploy');

        expect($projectUser->permissions['deploy'])->toBeFalse();
        expect($projectUser->permissions['view'])->toBeTrue();
    });

    it('sets all permissions at once', function () {
        $projectUser = new ProjectUser;

        $projectUser->setPermissions([
            'view' => true,
            'deploy' => true,
        ]);

        expect($projectUser->permissions['view'])->toBeTrue();
        expect($projectUser->permissions['deploy'])->toBeTrue();
        expect($projectUser->permissions['manage'])->toBeFalse();
        expect($projectUser->permissions['delete'])->toBeFalse();
    });

    it('grants full access', function () {
        $projectUser = new ProjectUser;

        $projectUser->grantFullAccess();

        expect($projectUser->permissions)->toBe(ProjectUser::FULL_ACCESS_PERMISSIONS);
        expect($projectUser->hasFullAccess())->toBeTrue();
    });

    it('grants view-only access', function () {
        $projectUser = new ProjectUser;

        $projectUser->grantViewOnly();

        expect($projectUser->permissions)->toBe(ProjectUser::VIEW_ONLY_PERMISSIONS);
        expect($projectUser->isViewOnly())->toBeTrue();
    });

    it('grants deploy access', function () {
        $projectUser = new ProjectUser;

        $projectUser->grantDeployAccess();

        expect($projectUser->permissions)->toBe(ProjectUser::DEPLOY_PERMISSIONS);
        expect($projectUser->canView())->toBeTrue();
        expect($projectUser->canDeploy())->toBeTrue();
        expect($projectUser->canManage())->toBeFalse();
    });

    it('correctly identifies full access', function () {
        $projectUser = new ProjectUser;
        $projectUser->permissions = ProjectUser::FULL_ACCESS_PERMISSIONS;

        expect($projectUser->hasFullAccess())->toBeTrue();

        $projectUser->permissions = ProjectUser::VIEW_ONLY_PERMISSIONS;
        expect($projectUser->hasFullAccess())->toBeFalse();
    });

    it('correctly identifies view-only access', function () {
        $projectUser = new ProjectUser;
        $projectUser->permissions = ProjectUser::VIEW_ONLY_PERMISSIONS;

        expect($projectUser->isViewOnly())->toBeTrue();

        $projectUser->permissions = ProjectUser::DEPLOY_PERMISSIONS;
        expect($projectUser->isViewOnly())->toBeFalse();
    });
});

describe('ProjectUser constants', function () {
    it('has correct default permissions', function () {
        expect(ProjectUser::DEFAULT_PERMISSIONS)->toBe([
            'view' => false,
            'deploy' => false,
            'manage' => false,
            'delete' => false,
        ]);
    });

    it('has correct full access permissions', function () {
        expect(ProjectUser::FULL_ACCESS_PERMISSIONS)->toBe([
            'view' => true,
            'deploy' => true,
            'manage' => true,
            'delete' => true,
        ]);
    });

    it('has correct view-only permissions', function () {
        expect(ProjectUser::VIEW_ONLY_PERMISSIONS)->toBe([
            'view' => true,
            'deploy' => false,
            'manage' => false,
            'delete' => false,
        ]);
    });

    it('has correct deploy permissions', function () {
        expect(ProjectUser::DEPLOY_PERMISSIONS)->toBe([
            'view' => true,
            'deploy' => true,
            'manage' => false,
            'delete' => false,
        ]);
    });
});
