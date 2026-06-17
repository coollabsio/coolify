<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->personal()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
});

it('allows unauthenticated access to two-factor-challenge page', function () {
    $response = $this->get('/two-factor-challenge');

    // Fortify returns a redirect to /login if there's no login.id in session,
    // but the important thing is it does NOT return a 419 or 500
    expect($response->status())->toBeIn([200, 302]);
});

it('includes two-factor-challenge in allowed paths for unsubscribed accounts', function () {
    $paths = allowedPathsForUnsubscribedAccounts();

    expect($paths)->toContain('two-factor-challenge');
});

it('includes two-factor-challenge in allowed paths for invalid accounts', function () {
    $paths = allowedPathsForInvalidAccounts();

    expect($paths)->toContain('two-factor-challenge');
});

it('includes two-factor-challenge in allowed paths for boarding accounts', function () {
    $paths = allowedPathsForBoardingAccounts();

    expect($paths)->toContain('two-factor-challenge');
});

it('does not redirect authenticated user with force_password_reset from two-factor-challenge', function () {
    $this->user->update(['force_password_reset' => true]);

    $response = $this->actingAs($this->user)->get('/two-factor-challenge');

    // Should NOT redirect to force-password-reset page
    if ($response->isRedirect()) {
        expect($response->headers->get('Location'))->not->toContain('force-password-reset');
    }
});

it('renders 419 error page with login link instead of previous url', function () {
    $response = $this->get('/two-factor-challenge', [
        'X-CSRF-TOKEN' => 'invalid-token',
    ]);

    // The 419 page should exist and contain a link to /login
    $view = view('errors.419')->render();

    expect($view)->toContain('/login');
    expect($view)->toContain('Back to Login');
    expect($view)->toContain('This page is definitely old, not like you!');
    expect($view)->not->toContain('url()->previous()');
});
