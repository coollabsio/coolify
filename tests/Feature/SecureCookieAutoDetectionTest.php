<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['fqdn' => null]));
    // Ensure session.secure starts unconfigured for each test
    config(['session.secure' => null]);
});

it('sets session.secure to true when request arrives over HTTPS via proxy', function () {
    $this->get('/login', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-For' => '1.2.3.4',
    ]);

    expect(config('session.secure'))->toBeTrue();
});

it('does not set session.secure for plain HTTP requests', function () {
    $this->get('/login');

    expect(config('session.secure'))->toBeNull();
});

it('does not override explicit SESSION_SECURE_COOKIE=false for HTTPS requests', function () {
    config(['session.secure' => false]);

    $this->get('/login', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-For' => '1.2.3.4',
    ]);

    // Explicit false must not be overridden — our check is `=== null` only
    expect(config('session.secure'))->toBeFalse();
});

it('does not override explicit SESSION_SECURE_COOKIE=true', function () {
    config(['session.secure' => true]);

    $this->get('/login');

    expect(config('session.secure'))->toBeTrue();
});

it('marks session cookie with Secure flag when accessed over HTTPS proxy', function () {
    // With no users, /login redirects to first-user registration instead of rendering
    User::factory()->create();

    $response = $this->get('/login', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-For' => '1.2.3.4',
    ]);

    $response->assertSuccessful();

    $cookieName = config('session.cookie');
    $sessionCookie = collect($response->headers->all('set-cookie'))
        ->first(fn ($c) => str_contains($c, $cookieName));

    expect($sessionCookie)->not->toBeNull()
        ->and(strtolower($sessionCookie))->toContain('; secure');
});

/**
 * @return array<string, string> the Set-Cookie headers of the response, by cookie name
 */
function responseCookiesByName($response): array
{
    return collect($response->headers->all('set-cookie'))
        ->mapWithKeys(fn (string $cookie) => [strstr($cookie, '=', true) => strtolower($cookie)])
        ->all();
}

it('gives plain HTTP its own session and XSRF cookies, so the Secure cookies of an HTTPS visit to the same host cannot block them (#4313)', function () {
    User::factory()->create();
    $sessionCookie = config('session.cookie');

    $cookies = responseCookiesByName($this->get('/login')->assertSuccessful());

    expect($cookies)->toHaveKeys([$sessionCookie.'_http', 'XSRF-TOKEN-HTTP'])
        ->not->toHaveKeys([$sessionCookie, 'XSRF-TOKEN'])
        ->and($cookies[$sessionCookie.'_http'])->not->toContain('; secure')
        ->and($cookies['XSRF-TOKEN-HTTP'])->not->toContain('; secure');
});

it('keeps the default Secure session and XSRF cookies for HTTPS', function () {
    User::factory()->create();
    $sessionCookie = config('session.cookie');

    $cookies = responseCookiesByName($this->get('/login', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-For' => '1.2.3.4',
    ])->assertSuccessful());

    expect($cookies)->toHaveKeys([$sessionCookie, 'XSRF-TOKEN'])
        ->and($cookies[$sessionCookie])->toContain('; secure')
        ->and($cookies['XSRF-TOKEN'])->toContain('; secure');
});

it('keeps the default cookie names on plain HTTP when SESSION_SECURE_COOKIE is set', function () {
    User::factory()->create();
    config(['session.secure' => false]);
    $sessionCookie = config('session.cookie');

    expect(responseCookiesByName($this->get('/login')->assertSuccessful()))
        ->toHaveKeys([$sessionCookie, 'XSRF-TOKEN']);
});
