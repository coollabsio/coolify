<?php

use App\Livewire\Dashboard;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createDashboardTestInvitation(Team $team, string $email, array $attributes = []): TeamInvitation
{
    $uuid = $attributes['uuid'] ?? (string) str()->uuid();

    return TeamInvitation::create(array_merge([
        'team_id' => $team->id,
        'uuid' => $uuid,
        'email' => $email,
        'role' => 'admin',
        'link' => url('/invitations/'.$uuid),
        'via' => 'link',
    ], $attributes));
}

beforeEach(function () {
    Queue::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create(['email' => 'invited@example.com']);
    $this->ownTeam = Team::factory()->create();
    $this->user->teams()->attach($this->ownTeam, ['role' => 'owner']);
    $this->invitingTeam = Team::factory()->create(['name' => 'Acme Platform']);

    Team::query()->update(['show_boarding' => false]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->ownTeam]);
});

it('shows a pending invitation for the user email with a review link', function () {
    $invitation = createDashboardTestInvitation($this->invitingTeam, 'Invited@Example.com');

    Livewire::test(Dashboard::class)
        ->assertSee('Acme Platform')
        ->assertSee('invited you as Admin.')
        ->assertSee('Review invitation')
        ->assertSee(route('team.invitation.show', $invitation->uuid), false);
});

it('does not show invitations for another email', function () {
    $invitation = createDashboardTestInvitation($this->invitingTeam, 'someone-else@example.com');

    Livewire::test(Dashboard::class)
        ->assertDontSee('Acme Platform')
        ->assertDontSee('Review invitation')
        ->assertDontSee(route('team.invitation.show', $invitation->uuid), false);
});

it('does not show expired invitations and keeps them in place', function () {
    $invitation = createDashboardTestInvitation($this->invitingTeam, 'invited@example.com');
    $invitation->forceFill([
        'created_at' => now()->subDays(config('constants.invitation.link.expiration_days') + 1),
    ])->save();

    Livewire::test(Dashboard::class)
        ->assertDontSee('Acme Platform')
        ->assertDontSee('Review invitation');

    expect(TeamInvitation::query()->whereKey($invitation->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($this->user->id)->exists())->toBeTrue();
});

it('does not show invitations to a team the user already belongs to', function () {
    $this->user->teams()->attach($this->invitingTeam, ['role' => 'member']);
    createDashboardTestInvitation($this->invitingTeam, 'invited@example.com');

    Livewire::test(Dashboard::class)
        ->assertDontSee('Acme Platform')
        ->assertDontSee('Review invitation');
});

it('no longer shows the invitation after it is accepted', function () {
    $invitation = createDashboardTestInvitation($this->invitingTeam, 'invited@example.com');

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('team.invitation.show', $invitation->uuid), false);

    $this->post(route('team.invitation.accept', $invitation->uuid))
        ->assertRedirect(route('team.index'));

    expect($this->user->teams()->whereKey($this->invitingTeam->id)->exists())->toBeTrue();

    $this->actingAs($this->user->fresh());
    session(['currentTeam' => $this->invitingTeam->fresh()]);

    Livewire::test(Dashboard::class)
        ->assertDontSee('Acme Platform')
        ->assertDontSee('Review invitation')
        ->assertDontSee(route('team.invitation.show', $invitation->uuid), false);
});
