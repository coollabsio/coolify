<?php

use Spatie\Url\Url;

/**
 * Regression tests for service domain FQDN port handling (#8798 / #8980).
 *
 * Fragile str()->after('://')->before(':') stripping corrupts URLs that contain
 * credentials, IPv6, or other non-trivial structure. getFqdnWithoutPort() must
 * strip only the port and keep a usable base URL for COOLIFY_URL / SERVICE_URL.
 */
test('getFqdnWithoutPort strips port from simple http urls without trailing slash', function () {
    expect(getFqdnWithoutPort('http://git.example.com:80'))->toBe('http://git.example.com')
        ->and(getFqdnWithoutPort('https://n8n.example.com:5678'))->toBe('https://n8n.example.com')
        ->and(getFqdnWithoutPort('http://git.example.com'))->toBe('http://git.example.com');
});

test('getFqdnWithoutPort preserves path segments when stripping port', function () {
    expect(getFqdnWithoutPort('http://git.example.com:80/v1/realtime'))
        ->toBe('http://git.example.com/v1/realtime');
});

test('getFqdnWithoutPort keeps host when credentials are present', function () {
    // Fragile strip would turn this into "http://user" — helper must not.
    expect(getFqdnWithoutPort('http://user:secret@git.example.com:80'))
        ->toBe('http://git.example.com');
});

test('getFqdnWithoutPort returns original string when input is not a valid url', function () {
    expect(getFqdnWithoutPort('not-a-url'))->toBe('not-a-url');
});

test('fragile after/before strip corrupts credential urls (documents #8798 class of bugs)', function () {
    $fqdn = 'http://user:secret@git.example.com:80';

    $fragile = (string) str($fqdn)
        ->after('://')
        ->before(':')
        ->prepend(str($fqdn)->before('://')->append('://'));

    expect($fragile)->toBe('http://user')
        ->and($fragile)->not->toBe(getFqdnWithoutPort($fqdn));
});

test('port comparisons must cast so string template ports match int url ports', function () {
    $envPort = Url::fromString('http://git.example.com:80')->getPort();
    $predefinedPort = '80'; // yaml / data_get style

    expect($envPort !== $predefinedPort)->toBeTrue('strict compare wrongly treats matching ports as different')
        ->and((int) $envPort !== (int) $predefinedPort)->toBeFalse();
});
