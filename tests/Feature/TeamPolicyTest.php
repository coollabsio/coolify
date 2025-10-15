<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a team with owner, admin, and member
    $this->team = Team::factory()->create();

    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();

    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);
    $this->team->members()->attach($this->member->id, ['role' => 'member']);
});

describe('update permission', function () {
    test('owner can update team', function () {
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);
        expect($this->owner->can('update', $this->team))->toBeTrue();
    });

    test('admin can update team', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        expect($this->admin->can('update', $this->team))->toBeTrue();
    });

    test('member cannot update team', function () {
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);
        expect($this->member->can('update', $this->team))->toBeFalse();
    });

    test('non-team member cannot update team', function () {
        $outsider = User::factory()->create();
        $this->actingAs($outsider);
        session(['currentTeam' => $this->team]);
        expect($outsider->can('update', $this->team))->toBeFalse();
    });
});

describe('delete permission', function () {
    test('owner can delete team', function () {
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);
        expect($this->owner->can('delete', $this->team))->toBeTrue();
    });

    test('admin can delete team', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        expect($this->admin->can('delete', $this->team))->toBeTrue();
    });

    test('member cannot delete team', function () {
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);
        expect($this->member->can('delete', $this->team))->toBeFalse();
    });

    test('non-team member cannot delete team', function () {
        $outsider = User::factory()->create();
        $this->actingAs($outsider);
        session(['currentTeam' => $this->team]);
        expect($outsider->can('delete', $this->team))->toBeFalse();
    });
});

describe('manageMembers permission', function () {
    test('owner can manage members', function () {
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);
        expect($this->owner->can('manageMembers', $this->team))->toBeTrue();
    });

    test('admin can manage members', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        expect($this->admin->can('manageMembers', $this->team))->toBeTrue();
    });

    test('member cannot manage members', function () {
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);
        expect($this->member->can('manageMembers', $this->team))->toBeFalse();
    });

    test('non-team member cannot manage members', function () {
        $outsider = User::factory()->create();
        $this->actingAs($outsider);
        session(['currentTeam' => $this->team]);
        expect($outsider->can('manageMembers', $this->team))->toBeFalse();
    });
});

describe('viewAdmin permission', function () {
    test('owner can view admin panel', function () {
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);
        expect($this->owner->can('viewAdmin', $this->team))->toBeTrue();
    });

    test('admin can view admin panel', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        expect($this->admin->can('viewAdmin', $this->team))->toBeTrue();
    });

    test('member cannot view admin panel', function () {
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);
        expect($this->member->can('viewAdmin', $this->team))->toBeFalse();
    });

    test('non-team member cannot view admin panel', function () {
        $outsider = User::factory()->create();
        $this->actingAs($outsider);
        session(['currentTeam' => $this->team]);
        expect($outsider->can('viewAdmin', $this->team))->toBeFalse();
    });
});

describe('manageInvitations permission (privilege escalation fix)', function () {
    test('owner can manage invitations', function () {
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);
        expect($this->owner->can('manageInvitations', $this->team))->toBeTrue();
    });

    test('admin can manage invitations', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        expect($this->admin->can('manageInvitations', $this->team))->toBeTrue();
    });

    test('member cannot manage invitations (SECURITY FIX)', function () {
        // This test verifies the privilege escalation vulnerability is fixed
        // Previously, members could see and manage admin invitations
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);
        expect($this->member->can('manageInvitations', $this->team))->toBeFalse();
    });

    test('non-team member cannot manage invitations', function () {
        $outsider = User::factory()->create();
        $this->actingAs($outsider);
        session(['currentTeam' => $this->team]);
        expect($outsider->can('manageInvitations', $this->team))->toBeFalse();
    });
});

describe('view permission', function () {
    test('owner can view team', function () {
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);
        expect($this->owner->can('view', $this->team))->toBeTrue();
    });

    test('admin can view team', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        expect($this->admin->can('view', $this->team))->toBeTrue();
    });

    test('member can view team', function () {
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);
        expect($this->member->can('view', $this->team))->toBeTrue();
    });

    test('non-team member cannot view team', function () {
        $outsider = User::factory()->create();
        $this->actingAs($outsider);
        session(['currentTeam' => $this->team]);
        expect($outsider->can('view', $this->team))->toBeFalse();
    });
});
