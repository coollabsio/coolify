<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

beforeEach(function () {
    // `id` is not mass-assignable; forceCreate so InstanceSettings::get() (id 0) works.
    InstanceSettings::forceCreate([
        'id' => 0,
        'is_sponsorship_popup_enabled' => false,
        'is_registration_enabled' => true,
    ]);
});

it('redirects to root registration when no users exist', function () {
    $page = visit('/login');

    $page->assertPathIs('/register')
        ->assertSee('Create the root account for this instance.')
        ->assertSee('Full instance access')
        ->assertSee('Create account')
        ->screenshot(filename: 'login-no-users-redirects-to-register');
});

it('shows the login form when a user exists', function () {
    createRootUser();

    $page = visit('/login');

    $page->assertPathIs('/login')
        ->assertSee('Sign in to manage your applications and infrastructure.')
        ->assertSee('Email')
        ->assertSee('Password')
        ->assertSee('Login')
        ->screenshot(filename: 'login-form');
});

it('can login with valid credentials', function () {
    createRootUser();

    $page = visit('/login');

    $page->screenshot(filename: 'login-valid-before')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->screenshot(filename: 'login-valid-filled')
        ->click('Login')
        ->assertSee('Welcome to Coolify')
        ->assertSee('Connect your first server and start deploying in minutes.')
        ->assertSee('Continue')
        ->assertSee('Skip setup')
        ->screenshot(filename: 'login-valid-onboarding');
});

it('fails login with invalid credentials', function () {
    createRootUser();

    $page = visit('/login');

    $page->fill('email', 'random@email.com')
        ->fill('password', 'wrongpassword123')
        ->click('Login')
        ->assertPathIs('/login')
        ->assertSee('These credentials do not match our records.')
        ->screenshot(filename: 'login-invalid-credentials');
});

it('prevents Enter from duplicating the automatic two factor challenge submission', function () {
    config(['app.maintenance.driver' => 'file']);

    $user = createRootUser();
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertPathIs('/two-factor-challenge');

    $page->script(<<<'JS'
        window.acceptedTwoFactorSubmissions = 0;

        document.querySelector('form[action="/two-factor-challenge"]').addEventListener('submit', (event) => {
            if (!event.defaultPrevented) {
                window.acceptedTwoFactorSubmissions++;
            }

            event.preventDefault();
        });
    JS);

    foreach (str_split('123456') as $index => $digit) {
        $page->keys(sprintf('[aria-label="Digit %d"]', $index + 1), $digit);
    }

    $page->keys('[aria-label="Digit 6"]', 'Enter')
        ->assertScript('window.acceptedTwoFactorSubmissions', 1)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'login-two-factor-enter-single-submission');
});

/**
 * Create the root user (id 0) with known credentials for browser login tests.
 */
function createRootUser(): User
{
    return User::forceCreate([
        'id' => 0,
        'name' => 'Root User',
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);
}
