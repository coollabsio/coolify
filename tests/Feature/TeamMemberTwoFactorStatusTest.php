<?php

use App\Livewire\Team\Member;
use App\Livewire\Team\Member\Index;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
    ]));

    $this->team = Team::factory()->create();
});

function createTeamMember(Team $team, string $role, bool $twoFactorEnabled = false): User
{
    $user = User::factory()->create([
        'name' => fake()->unique()->userName(),
        'two_factor_confirmed_at' => $twoFactorEnabled ? now() : null,
    ]);

    $team->members()->attach($user->id, ['role' => $role]);

    return $user;
}

function actAsTeamMember(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

test('admins see the two factor column with the status of every member', function () {
    $admin = createTeamMember($this->team, 'admin', twoFactorEnabled: true);
    createTeamMember($this->team, 'member');

    actAsTeamMember($admin, $this->team);

    Livewire::test(Index::class)
        ->assertSee('2FA')
        ->assertSee('Two-factor authentication is enabled')
        ->assertSee('Two-factor authentication is disabled');
});

test('owners see the two factor column and summary', function () {
    $owner = createTeamMember($this->team, 'owner', twoFactorEnabled: true);
    createTeamMember($this->team, 'member');

    actAsTeamMember($owner, $this->team);

    Livewire::test(Index::class)
        ->assertSee('2FA')
        ->assertSee('1 of 2 members does not have two-factor authentication enabled');
});

test('members without member management rights do not see the two factor column', function () {
    createTeamMember($this->team, 'owner', twoFactorEnabled: true);
    $member = createTeamMember($this->team, 'member');

    actAsTeamMember($member, $this->team);

    Livewire::test(Index::class)
        ->assertDontSee('2FA')
        ->assertDontSee('Two-factor authentication is enabled')
        ->assertDontSee('Two-factor authentication is disabled')
        ->assertDontSee('two-factor authentication enabled');
});

test('the member row renders an enabled badge when two factor is confirmed', function () {
    $admin = createTeamMember($this->team, 'admin');
    $memberWithTwoFactor = createTeamMember($this->team, 'member', twoFactorEnabled: true);

    actAsTeamMember($admin, $this->team);

    Livewire::test(Member::class, ['member' => $memberWithTwoFactor])
        ->assertSee('Two-factor authentication is enabled')
        ->assertDontSee('Two-factor authentication is disabled');
});

test('the member row renders a disabled badge when two factor is not configured', function () {
    $admin = createTeamMember($this->team, 'admin');
    $memberWithoutTwoFactor = createTeamMember($this->team, 'member');

    actAsTeamMember($admin, $this->team);

    Livewire::test(Member::class, ['member' => $memberWithoutTwoFactor])
        ->assertSee('Two-factor authentication is disabled')
        ->assertDontSee('Two-factor authentication is enabled');
});

test('admins see the two factor status of every role, including owners and other admins', function (string $role, bool $twoFactorEnabled, string $expectedStatus) {
    $admin = createTeamMember($this->team, 'admin');
    $otherMember = createTeamMember($this->team, $role, twoFactorEnabled: $twoFactorEnabled);

    actAsTeamMember($admin, $this->team);

    Livewire::test(Member::class, ['member' => $otherMember])
        ->assertSee($expectedStatus);
})->with([
    'owner with two factor' => ['owner', true, 'Two-factor authentication is enabled'],
    'owner without two factor' => ['owner', false, 'Two-factor authentication is disabled'],
    'another admin with two factor' => ['admin', true, 'Two-factor authentication is enabled'],
    'another admin without two factor' => ['admin', false, 'Two-factor authentication is disabled'],
    'member with two factor' => ['member', true, 'Two-factor authentication is enabled'],
    'member without two factor' => ['member', false, 'Two-factor authentication is disabled'],
]);

test('admins see the two factor status on their own row', function () {
    $admin = createTeamMember($this->team, 'admin', twoFactorEnabled: true);

    actAsTeamMember($admin, $this->team);

    Livewire::test(Member::class, ['member' => $admin])
        ->assertSee('You')
        ->assertSee('Two-factor authentication is enabled');
});

test('the summary counts the members that are missing two factor authentication', function () {
    $admin = createTeamMember($this->team, 'admin', twoFactorEnabled: true);
    createTeamMember($this->team, 'member');

    actAsTeamMember($admin, $this->team);

    Livewire::test(Index::class)
        ->assertSee('1 of 2')
        ->assertSee('does not have two-factor authentication enabled');
});

test('the summary confirms when every member has two factor authentication', function () {
    $admin = createTeamMember($this->team, 'admin', twoFactorEnabled: true);
    createTeamMember($this->team, 'member', twoFactorEnabled: true);

    actAsTeamMember($admin, $this->team);

    Livewire::test(Index::class)
        ->assertSee('All members have two-factor authentication enabled')
        ->assertDontSee('do not have two-factor authentication enabled');
});
