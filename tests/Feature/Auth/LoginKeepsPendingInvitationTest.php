<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $this->personalTeam = $this->invitee->teams->first();
    $this->personalTeam->update(['show_boarding' => false]);

    $this->invitingTeam = Team::factory()->create(['show_boarding' => false]);
    $this->invitation = TeamInvitation::create([
        'team_id' => $this->invitingTeam->id,
        'uuid' => 'pending-login-invitation',
        'email' => $this->invitee->email,
        'role' => 'admin',
        'link' => route('team.invitation.show', ['uuid' => 'pending-login-invitation']),
        'via' => 'email',
    ]);
});

it('does not accept a pending invitation on password login', function () {
    $this->post('/login', ['email' => $this->invitee->email, 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($this->invitee);
    expect($this->invitee->teams()->where('team_id', $this->invitingTeam->id)->exists())->toBeFalse()
        ->and(data_get(session('currentTeam'), 'id'))->toBe($this->personalTeam->id);
    $this->assertDatabaseHas('team_invitations', ['id' => $this->invitation->id]);
});

it('lets the user accept the invitation explicitly after login', function () {
    $this->post('/login', ['email' => $this->invitee->email, 'password' => 'password']);

    $this->get(route('team.invitation.show', $this->invitation->uuid))
        ->assertSuccessful()
        ->assertSee($this->invitingTeam->name);

    $this->post(route('team.invitation.accept', $this->invitation->uuid))
        ->assertRedirect(route('team.index'));

    expect($this->invitee->teams()->where('team_id', $this->invitingTeam->id)->first()?->pivot->role)->toBe('admin')
        ->and(data_get(session('currentTeam'), 'id'))->toBe($this->invitingTeam->id);
    $this->assertDatabaseMissing('team_invitations', ['id' => $this->invitation->id]);
});

it('returns to the invitation page after logging in from the invitation link', function () {
    $this->get(route('team.invitation.show', $this->invitation->uuid))
        ->assertRedirect(route('login'));

    $this->post('/login', ['email' => $this->invitee->email, 'password' => 'password'])
        ->assertRedirect(route('team.invitation.show', $this->invitation->uuid));

    $this->get(route('team.invitation.show', $this->invitation->uuid))
        ->assertSuccessful()
        ->assertSee($this->invitingTeam->name);
});
