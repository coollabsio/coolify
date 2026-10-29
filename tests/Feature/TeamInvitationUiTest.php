<?php

use App\Livewire\Team\Invitations;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['fqdn' => null]));

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
});

it('preserves a provisional user when revoking their invitation fails', function () {
    $provisionalUser = User::factory()->create([
        'email' => 'provisional@example.com',
        'email_verified_at' => null,
        'force_password_reset' => true,
    ]);
    $invitation = TeamInvitation::create([
        'team_id' => $this->team->id,
        'uuid' => 'failing-invitation-delete',
        'email' => $provisionalUser->email,
        'role' => 'member',
        'link' => 'http://example.test/invitations/failing-invitation-delete',
        'via' => 'link',
    ]);

    TeamInvitation::deleting(function (): void {
        throw new RuntimeException('Invitation deletion failed.');
    });

    Livewire::test(Invitations::class, [
        'invitations' => collect([$invitation]),
    ])
        ->call('deleteInvitation', $invitation->id)
        ->assertDispatched('error');

    $this->assertDatabaseHas('users', ['id' => $provisionalUser->id]);
    $this->assertDatabaseHas('team_invitations', ['id' => $invitation->id]);
});

it('sorts pending invitations by the chosen column and ignores unknown sort values', function () {
    $invite = fn (string $email, string $role, string $via) => TeamInvitation::create([
        'team_id' => $this->team->id,
        'uuid' => $email,
        'email' => $email,
        'role' => $role,
        'link' => "http://example.test/invitations/{$email}",
        'via' => $via,
    ]);
    $invitations = collect([
        $invite('carol@example.com', 'member', 'email'),
        $invite('alice@example.com', 'owner', 'link'),
        $invite('bob@example.com', 'admin', 'email'),
    ]);

    Livewire::test(Invitations::class, ['invitations' => $invitations])
        ->assertSeeInOrder(['carol@example.com', 'alice@example.com', 'bob@example.com'])
        ->call('setSort', 'email-asc')
        ->assertSeeInOrder(['alice@example.com', 'bob@example.com', 'carol@example.com'])
        ->call('setSort', 'role-desc')
        ->assertSeeInOrder(['alice@example.com', 'bob@example.com', 'carol@example.com'])
        ->call('setSort', 'role-asc')
        ->assertSeeInOrder(['carol@example.com', 'bob@example.com', 'alice@example.com'])
        ->call('setSort', 'via-desc')
        ->assertSeeInOrder(['alice@example.com', 'bob@example.com', 'carol@example.com'])
        ->call('setSort', 'link-asc')
        ->assertSet('sort', 'via-desc');
});
