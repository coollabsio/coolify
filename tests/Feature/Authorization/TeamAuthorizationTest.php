<?php

use App\Livewire\Team\Index as TeamIndex;
use App\Livewire\Team\Member as TeamMember;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0]);

    $this->team = Team::factory()->create(['personal_team' => false]);

    $this->owner = User::factory()->create();
    $this->owner->teams()->attach($this->team, ['role' => 'owner']);

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);
});

// --- Team Policy: update ---

test('owner can update team', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('update', $this->team))->toBeTrue();
});

test('admin can update team', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('update', $this->team))->toBeTrue();
});

test('member cannot update team', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('update', $this->team))->toBeFalse();
});

// --- Team Policy: delete ---

test('owner can delete team', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('delete', $this->team))->toBeTrue();
});

test('admin can delete team', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('delete', $this->team))->toBeTrue();
});

test('member cannot delete team', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('delete', $this->team))->toBeFalse();
});

// --- Team Policy: manageMembers ---

test('owner can manage team members', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('manageMembers', $this->team))->toBeTrue();
});

test('admin can manage team members', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('manageMembers', $this->team))->toBeTrue();
});

test('member cannot manage team members', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('manageMembers', $this->team))->toBeFalse();
});

// --- Team Policy: manageInvitations ---

test('owner can manage invitations', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('manageInvitations', $this->team))->toBeTrue();
});

test('admin can manage invitations', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('manageInvitations', $this->team))->toBeTrue();
});

test('member cannot manage invitations', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('manageInvitations', $this->team))->toBeFalse();
});

// --- Team Index Livewire: update ---

test('member cannot submit team settings via policy', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('update', $this->team))->toBeFalse();
});

// --- Team Index Livewire: delete ---

test('member cannot delete team via index', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(TeamIndex::class)
        ->call('delete', 'password')
        ->assertDispatched('error');

    expect(Team::find($this->team->id))->not->toBeNull();
});

test('admin can delete team via policy', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('delete', $this->team))->toBeTrue();
});

// --- Team Member Livewire: role changes ---

test('member cannot change roles via team member component', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(TeamMember::class, ['member' => $this->admin])
        ->call('makeAdmin')
        ->assertDispatched('error');
});

test('member cannot remove team members', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(TeamMember::class, ['member' => $this->admin])
        ->call('remove')
        ->assertDispatched('error');
});

// --- Invitations & Invite Link (policy checks — views wrapped in @can render empty for members) ---

test('member cannot manage invitations via policy', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('manageInvitations', $this->team))->toBeFalse();
});

test('owner can manage invitations via policy', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('manageInvitations', $this->team))->toBeTrue();
});

// --- Cross-team isolation ---

test('user from different team cannot update team', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'owner']);

    $this->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);

    expect(auth()->user()->can('update', $this->team))->toBeFalse();
});

test('user from different team cannot manage members', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'owner']);

    $this->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);

    expect(auth()->user()->can('manageMembers', $this->team))->toBeFalse();
});

// --- Team Policy: view ---

test('owner can view team', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('view', $this->team))->toBeTrue();
});

test('admin can view team', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('view', $this->team))->toBeTrue();
});

test('member can view team', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('view', $this->team))->toBeTrue();
});

test('non-team member cannot view team', function () {
    $outsider = User::factory()->create();
    $this->actingAs($outsider);
    session(['currentTeam' => $this->team]);

    expect($outsider->can('view', $this->team))->toBeFalse();
});

// --- Team Policy: viewAdmin ---

test('owner can view admin panel', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('viewAdmin', $this->team))->toBeTrue();
});

test('admin can view admin panel', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('viewAdmin', $this->team))->toBeTrue();
});

test('member cannot view admin panel', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('viewAdmin', $this->team))->toBeFalse();
});

test('non-team member cannot view admin panel', function () {
    $outsider = User::factory()->create();
    $this->actingAs($outsider);
    session(['currentTeam' => $this->team]);

    expect($outsider->can('viewAdmin', $this->team))->toBeFalse();
});

// --- Non-team member isolation ---

test('non-team member cannot update team', function () {
    $outsider = User::factory()->create();
    $this->actingAs($outsider);
    session(['currentTeam' => $this->team]);

    expect($outsider->can('update', $this->team))->toBeFalse();
});

test('non-team member cannot delete team', function () {
    $outsider = User::factory()->create();
    $this->actingAs($outsider);
    session(['currentTeam' => $this->team]);

    expect($outsider->can('delete', $this->team))->toBeFalse();
});

test('non-team member cannot manage members', function () {
    $outsider = User::factory()->create();
    $this->actingAs($outsider);
    session(['currentTeam' => $this->team]);

    expect($outsider->can('manageMembers', $this->team))->toBeFalse();
});

test('non-team member cannot manage invitations', function () {
    $outsider = User::factory()->create();
    $this->actingAs($outsider);
    session(['currentTeam' => $this->team]);

    expect($outsider->can('manageInvitations', $this->team))->toBeFalse();
});

// --- Team creation and model-level protection ---

test('member can create a new independent team', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $newTeam = Team::create([
        'name' => 'New Team',
        'description' => 'Created by member',
        'personal_team' => false,
    ]);

    expect($newTeam)->toBeInstanceOf(Team::class)
        ->and($newTeam->name)->toBe('New Team');
});

test('member cannot update an existing team at model level', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(fn () => $this->team->update(['name' => 'Hacked']))
        ->toThrow(Exception::class, 'You are not allowed to update this team.');
});
