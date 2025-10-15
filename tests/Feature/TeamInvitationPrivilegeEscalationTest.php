<?php

use App\Livewire\Team\InviteLink;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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

describe('privilege escalation prevention', function () {
    test('member cannot invite admin (SECURITY FIX)', function () {
        // Login as member
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);

        // Attempt to invite someone as admin
        Livewire::test(InviteLink::class)
            ->set('email', 'newadmin@example.com')
            ->set('role', 'admin')
            ->call('viaLink')
            ->assertDispatched('error');
    });

    test('member cannot invite owner (SECURITY FIX)', function () {
        // Login as member
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);

        // Attempt to invite someone as owner
        Livewire::test(InviteLink::class)
            ->set('email', 'newowner@example.com')
            ->set('role', 'owner')
            ->call('viaLink')
            ->assertDispatched('error');
    });

    test('admin cannot invite owner', function () {
        // Login as admin
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        // Attempt to invite someone as owner
        Livewire::test(InviteLink::class)
            ->set('email', 'newowner@example.com')
            ->set('role', 'owner')
            ->call('viaLink')
            ->assertDispatched('error');
    });

    test('admin can invite member', function () {
        // Login as admin
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        // Invite someone as member
        Livewire::test(InviteLink::class)
            ->set('email', 'newmember@example.com')
            ->set('role', 'member')
            ->call('viaLink')
            ->assertDispatched('success');

        // Verify invitation was created
        $this->assertDatabaseHas('team_invitations', [
            'email' => 'newmember@example.com',
            'role' => 'member',
            'team_id' => $this->team->id,
        ]);
    });

    test('admin can invite admin', function () {
        // Login as admin
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        // Invite someone as admin
        Livewire::test(InviteLink::class)
            ->set('email', 'newadmin@example.com')
            ->set('role', 'admin')
            ->call('viaLink')
            ->assertDispatched('success');

        // Verify invitation was created
        $this->assertDatabaseHas('team_invitations', [
            'email' => 'newadmin@example.com',
            'role' => 'admin',
            'team_id' => $this->team->id,
        ]);
    });

    test('owner can invite member', function () {
        // Login as owner
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        // Invite someone as member
        Livewire::test(InviteLink::class)
            ->set('email', 'newmember@example.com')
            ->set('role', 'member')
            ->call('viaLink')
            ->assertDispatched('success');

        // Verify invitation was created
        $this->assertDatabaseHas('team_invitations', [
            'email' => 'newmember@example.com',
            'role' => 'member',
            'team_id' => $this->team->id,
        ]);
    });

    test('owner can invite admin', function () {
        // Login as owner
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        // Invite someone as admin
        Livewire::test(InviteLink::class)
            ->set('email', 'newadmin@example.com')
            ->set('role', 'admin')
            ->call('viaLink')
            ->assertDispatched('success');

        // Verify invitation was created
        $this->assertDatabaseHas('team_invitations', [
            'email' => 'newadmin@example.com',
            'role' => 'admin',
            'team_id' => $this->team->id,
        ]);
    });

    test('owner can invite owner', function () {
        // Login as owner
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        // Invite someone as owner
        Livewire::test(InviteLink::class)
            ->set('email', 'newowner@example.com')
            ->set('role', 'owner')
            ->call('viaLink')
            ->assertDispatched('success');

        // Verify invitation was created
        $this->assertDatabaseHas('team_invitations', [
            'email' => 'newowner@example.com',
            'role' => 'owner',
            'team_id' => $this->team->id,
        ]);
    });

    test('member cannot bypass policy by calling viaEmail', function () {
        // Login as member
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);

        // Attempt to invite someone as admin via email
        Livewire::test(InviteLink::class)
            ->set('email', 'newadmin@example.com')
            ->set('role', 'admin')
            ->call('viaEmail')
            ->assertDispatched('error');
    });
});
