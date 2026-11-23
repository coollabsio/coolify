<?php

use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    $this->withoutVite();
    Log::spy();

    $this->user = User::factory()->create(['name' => 'Passkey User']);
    $this->team = $this->user->teams()->sole();
});

it('allows the authenticated passkey paths for unsubscribed and boarding accounts', function () {
    expect(allowedPathsForBoardingAccounts())->toContain(
        'user/passkeys',
        'user/passkeys/options',
        'passkeys/confirm',
        'passkeys/confirm/options',
        'profile/passkeys/confirm',
    );
});

it('sets the session team after a login that did not set one', function () {
    $this->actingAs($this->user)->get('/profile')->assertOk();

    expect(session('currentTeam')->id)->toBe($this->team->id);
});

it('sends a multi team user without a stored team to the team selection', function () {
    $other = Team::factory()->create();
    $other->members()->attach($this->user->id, ['role' => 'member']);

    $this->actingAs($this->user)->get('/profile')->assertRedirect(route('team.select'));
});

it('asks for the password before the add passkey form opens', function () {
    $this->actingAs($this->user)
        ->withSession(['currentTeam' => $this->team])
        ->get(route('profile.passkeys.confirm'))
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($this->user)
        ->withSession(['currentTeam' => $this->team, 'auth.password_confirmed_at' => time()])
        ->get(route('profile.passkeys.confirm'))
        ->assertRedirect(route('profile'))
        ->assertSessionHas('status', 'passkey-add');
});

it('lets restricted accounts confirm their password before adding a passkey', function (bool $selfHosted) {
    config()->set('constants.coolify.self_hosted', $selfHosted);
    $this->team->update(['show_boarding' => true]);
    $this->actingAs($this->user)->withSession(['currentTeam' => $this->team]);

    $this->get(route('profile.passkeys.confirm'))->assertRedirect(route('password.confirm'));
    $this->get(route('password.confirm'))->assertOk();
    $this->post(route('password.confirm.store'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password');
    expect(session('auth.password_confirmed_at'))->toBeNull();

    $this->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertRedirect(route('profile.passkeys.confirm'));
    expect(session('auth.password_confirmed_at'))->toBeInt();
    $this->get(route('profile.passkeys.confirm'))
        ->assertRedirect(route('profile'))
        ->assertSessionHas('status', 'passkey-add');
    $this->getJson(route('passkey.registration-options'))->assertOk();
})->with(['onboarding' => true, 'cloud without a subscription' => false]);

it('records audit events for passkey changes', function (string $eventClass, string $auditName) {
    $passkey = $this->user->passkeys()->create([
        'name' => 'Laptop',
        'credential_id' => 'credential-1',
        'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);

    event(new $eventClass($this->user, $passkey));

    expect(AuditEvent::query()->where('event', $auditName)->count())->toBe(1);
})->with([
    [PasskeyRegistered::class, 'auth.user.passkey_registered'],
    [PasskeyVerified::class, 'auth.user.passkey_verified'],
    [PasskeyDeleted::class, 'auth.user.passkey_deleted'],
]);
