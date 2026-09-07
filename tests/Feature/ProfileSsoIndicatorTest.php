<?php

use App\Livewire\Profile\Index as ProfileIndex;
use App\Models\OauthIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows when the profile user signed in with sso', function () {
    $user = User::factory()->create(['name' => 'Profile User']);

    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'oidc',
        'issuer' => 'https://idp.example.com',
        'provider_user_id' => 'idp-user-1',
        'email' => $user->email,
    ]);

    $this->actingAs($user);

    Livewire::test(ProfileIndex::class)
        ->assertSee('Signed in with SSO')
        ->assertSee('OIDC');
});

it('does not show sso status for password-only profile users', function () {
    $user = User::factory()->create(['name' => 'Profile User']);

    $this->actingAs($user);

    Livewire::test(ProfileIndex::class)
        ->assertDontSee('Signed in with SSO');
});

it('prevents sso linked users from opening or requesting profile email changes', function () {
    $user = User::factory()->create(['name' => 'SSO User', 'email' => 'sso@example.com']);

    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'oidc',
        'issuer' => 'https://idp.example.com',
        'provider_user_id' => 'idp-user-1',
        'email' => $user->email,
    ]);

    $this->actingAs($user);

    Livewire::test(ProfileIndex::class)
        ->assertSee('Email is managed by your SSO provider.')
        ->call('showEmailChangeForm')
        ->assertSet('show_email_change', false)
        ->assertDispatched('error')
        ->set('new_email', 'changed@example.com')
        ->call('requestEmailChange')
        ->assertSet('show_email_change', false)
        ->assertSet('show_verification', false)
        ->assertDispatched('error');

    $user->refresh();

    expect($user->email)->toBe('sso@example.com')
        ->and($user->pending_email)->toBeNull()
        ->and($user->email_change_code)->toBeNull()
        ->and($user->email_change_code_expires_at)->toBeNull();
});

it('keeps profile email changes available for password-only users', function () {
    config()->set('constants.coolify.self_hosted', false);
    Notification::fake();

    $user = User::factory()->create(['name' => 'Password User', 'email' => 'password@example.com']);

    $this->actingAs($user);

    Livewire::test(ProfileIndex::class)
        ->call('showEmailChangeForm')
        ->assertSet('show_email_change', true)
        ->set('new_email', 'changed@example.com')
        ->call('requestEmailChange')
        ->assertSet('show_verification', true)
        ->assertDispatched('success');

    $user->refresh();

    expect($user->pending_email)->toBe('changed@example.com')
        ->and($user->email_change_code)->not->toBeNull();
});
