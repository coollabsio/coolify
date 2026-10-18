<?php

use App\Livewire\Team\AdminView;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->rootTeam = Team::factory()->create(['id' => 0]);
    $this->rootUser = User::factory()->create(['id' => 0]);

    $this->rootAdmin = User::factory()->create();
    $this->rootTeam->members()->attach($this->rootAdmin->id, ['role' => 'admin']);

    $this->rootOwner = User::factory()->create();
    $this->rootTeam->members()->attach($this->rootOwner->id, ['role' => 'owner']);
});

function actAsRootTeamMemberForAdminViewDeletion(User $user): void
{
    test()->actingAs($user);
    session(['currentTeam' => Team::find(0)]);
}

test('a root team admin cannot delete the root user', function () {
    actAsRootTeamMemberForAdminViewDeletion($this->rootAdmin);

    Livewire::test(AdminView::class)
        ->call('delete', 0, 'password')
        ->assertDispatched('error', 'The root user cannot be deleted.');

    expect(User::find(0))->not->toBeNull();
});

test('a root team admin cannot delete a root team owner', function () {
    actAsRootTeamMemberForAdminViewDeletion($this->rootAdmin);

    Livewire::test(AdminView::class)
        ->call('delete', $this->rootOwner->id, 'password')
        ->assertDispatched('error', 'You cannot delete a user with a higher role in the root team.');

    expect(User::find($this->rootOwner->id))->not->toBeNull();
});

test('a root team owner cannot delete the root user', function () {
    actAsRootTeamMemberForAdminViewDeletion($this->rootOwner);

    Livewire::test(AdminView::class)
        ->call('delete', 0, 'password')
        ->assertDispatched('error', 'The root user cannot be deleted.');

    expect(User::find(0))->not->toBeNull();
});

test('an instance admin cannot delete their own account from the admin view', function () {
    actAsRootTeamMemberForAdminViewDeletion($this->rootAdmin);

    Livewire::test(AdminView::class)
        ->call('delete', $this->rootAdmin->id, 'password')
        ->assertDispatched('error', 'Delete your own account from your profile.');

    expect(User::find($this->rootAdmin->id))->not->toBeNull();
});

test('a root team owner can delete a root team admin and a member', function () {
    $member = User::factory()->create();
    $this->rootTeam->members()->attach($member->id, ['role' => 'member']);

    actAsRootTeamMemberForAdminViewDeletion($this->rootOwner);

    Livewire::test(AdminView::class)
        ->call('delete', $this->rootAdmin->id, 'password')
        ->assertReturned(true)
        ->call('delete', $member->id, 'password')
        ->assertReturned(true);

    expect(User::find($this->rootAdmin->id))->toBeNull()
        ->and(User::find($member->id))->toBeNull();
});

test('a root team admin can delete another root team admin and a user outside the root team', function () {
    $otherAdmin = User::factory()->create();
    $this->rootTeam->members()->attach($otherAdmin->id, ['role' => 'admin']);
    $outsider = User::factory()->create();

    actAsRootTeamMemberForAdminViewDeletion($this->rootAdmin);

    Livewire::test(AdminView::class)
        ->call('delete', $otherAdmin->id, 'password')
        ->assertReturned(true)
        ->call('delete', $outsider->id, 'password')
        ->assertReturned(true);

    expect(User::find($otherAdmin->id))->toBeNull()
        ->and(User::find($outsider->id))->toBeNull();
});
