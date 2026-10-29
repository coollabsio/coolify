<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate([
        'id' => 0,
        'is_sponsorship_popup_enabled' => false,
    ]);
});

it('makes a member configure 2fa before using a team that requires it', function () {
    $user = User::forceCreate([
        'id' => 0,
        'name' => 'Root User',
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);
    $team = Team::query()->findOrFail(0);
    $team->update(['show_boarding' => false, 'is_two_factor_required' => true]);
    Cache::flush();

    $page = visit('/login');
    $page->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertPathIs('/two-factor-required')
        ->assertSee('Two-factor authentication required')
        ->screenshot(filename: 'team-2fa-required');

    $page->click('Configure 2FA')
        ->assertPathIs('/user/confirm-password')
        ->fill('input[name="password"]', 'password')
        ->click('form[action="/user/confirm-password"] button[type=submit]')
        ->assertPathIs('/two-factor-required')
        ->click('Configure 2FA')
        ->assertSee('Scan the QR code')
        ->screenshot(filename: 'team-2fa-qr-code');

    $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
    $page->fill('input[name="code"]', (new Google2FA)->getCurrentOtp($secret))
        ->click('Validate 2FA')
        ->assertSee('Save these recovery codes')
        ->screenshot(filename: 'team-2fa-recovery-codes');

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();

    $page->navigate('/profile')
        ->assertPathIs('/profile')
        ->screenshot(filename: 'team-2fa-profile-after-setup');
});
