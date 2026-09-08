<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
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

it('uses one mobile-friendly field for authenticator code paste and autofill', function () {
    $challenge = file_get_contents(resource_path('views/auth/two-factor-challenge.blade.php'));

    expect($challenge)
        ->toContain('name="code"')
        ->toContain('autocomplete="one-time-code"')
        ->toContain('inputmode="numeric"')
        ->toContain('maxlength="6"')
        ->toContain('@input="submitAuthenticatorCode($event)"')
        ->not->toContain('x-for="(digit, index) in digits"');
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
    expect($view)->toContain('Back to login');
    expect($view)->toContain('This page is definitely old, not like you!');
    expect($view)->toContain('error-shell');
    expect($view)->not->toContain('url()->previous()');
});

it('redirects an authenticated stale two-factor submission home instead of showing 419', function () {
    $request = Request::create('/two-factor-challenge', 'POST');
    $request->setRouteResolver(fn () => Route::getRoutes()->match($request));
    $request->setUserResolver(fn () => $this->user);

    $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException('CSRF token mismatch.'));

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe(url('/'));
});

it('still returns 419 for a stale two-factor submission without an authenticated session', function () {
    $request = Request::create('/two-factor-challenge', 'POST');
    $request->setRouteResolver(fn () => Route::getRoutes()->match($request));

    $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException('CSRF token mismatch.'));

    expect($response->getStatusCode())->toBe(419);
});

it('keeps the 419 for stale tokens on routes other than login and the two-factor challenge', function () {
    $request = Request::create('/two-factor-challenge', 'GET');
    $request->setRouteResolver(fn () => Route::getRoutes()->match($request));
    $request->setUserResolver(fn () => $this->user);

    $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException('CSRF token mismatch.'));

    expect($response->getStatusCode())->toBe(419);
});
