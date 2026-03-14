<?php

/**
 * Unit tests for GitHub issue #8798:
 * Service domain URLs with ports are corrupted to invalid format.
 *
 * When a one-click service has a predefined port (e.g., GitLab with port 80),
 * changing or saving the domain FQDN causes the URL to be mangled.
 * Example: http://git.example.com:80 becomes //:80git.example.com
 */

use Spatie\Url\Url;

test('ensureUrlHasScheme adds http:// to scheme-less URLs', function () {
    expect(ensureUrlHasScheme('git.example.com'))->toBe('http://git.example.com')
        ->and(ensureUrlHasScheme('git.example.com:80'))->toBe('http://git.example.com:80')
        ->and(ensureUrlHasScheme('http://git.example.com'))->toBe('http://git.example.com')
        ->and(ensureUrlHasScheme('https://git.example.com'))->toBe('https://git.example.com')
        ->and(ensureUrlHasScheme('http://git.example.com:80'))->toBe('http://git.example.com:80')
        ->and(ensureUrlHasScheme(''))->toBe('');
});

test('Url::fromString with ensureUrlHasScheme correctly parses host-only input', function () {
    $url = Url::fromString(ensureUrlHasScheme('git.example.com'));

    expect($url->getScheme())->toBe('http')
        ->and($url->getHost())->toBe('git.example.com')
        ->and($url->getPort())->toBeNull();
});

test('Url::fromString with ensureUrlHasScheme correctly parses host:port input', function () {
    $url = Url::fromString(ensureUrlHasScheme('git.example.com:80'));

    expect($url->getScheme())->toBe('http')
        ->and($url->getHost())->toBe('git.example.com')
        ->and($url->getPort())->toBe(80);
});

test('withPort on scheme-less URL no longer produces mangled output', function () {
    // This was the exact bug: Url::fromString('git.example.com')->withPort(80)
    // produced //:80git.example.com instead of http://git.example.com:80
    $url = Url::fromString(ensureUrlHasScheme('git.example.com'));
    $urlWithPort = $url->withPort(80);

    expect($urlWithPort->__toString())->toBe('http://git.example.com:80')
        ->and($urlWithPort->__toString())->not->toBe('//:80git.example.com');
});

test('withPort on scheme-less host:port URL produces correct output', function () {
    $url = Url::fromString(ensureUrlHasScheme('git.example.com:80'));
    $urlWithPort = $url->withPort(80);

    expect($urlWithPort->__toString())->toBe('http://git.example.com:80');
});

test('getFqdnWithoutPort handles scheme-less FQDN correctly', function () {
    $result = getFqdnWithoutPort('git.example.com:80');

    expect($result)->toBe('http://git.example.com/')
        ->and($result)->not->toContain('//:80');
});

test('getFqdnWithoutPort handles normal URL correctly', function () {
    $result = getFqdnWithoutPort('http://git.example.com:80');

    expect($result)->toBe('http://git.example.com/');
});

test('URL reconstruction with scheme produces valid output', function () {
    $fqdn = 'http://git.example.com:80';
    $parsedUrl = Url::fromString(ensureUrlHasScheme($fqdn));
    $result = $parsedUrl->getScheme().'://'.$parsedUrl->getHost();

    expect($result)->toBe('http://git.example.com');
});

test('URL reconstruction without scheme produces valid output', function () {
    $fqdn = 'git.example.com:80';
    $parsedUrl = Url::fromString(ensureUrlHasScheme($fqdn));
    $result = $parsedUrl->getScheme().'://'.$parsedUrl->getHost();

    expect($result)->toBe('http://git.example.com');
});

test('port comparison uses loose equality to avoid type mismatch', function () {
    $url = Url::fromString('http://git.example.com:80');
    $envPort = $url->getPort(); // int 80
    $predefinedPort = '80'; // string from template

    // Strict comparison (the bug): 80 !== '80' is TRUE (causes unnecessary port rewrite)
    expect($envPort !== $predefinedPort)->toBeTrue();

    // Loose comparison (the fix): 80 != '80' is FALSE (ports match correctly)
    expect($envPort != $predefinedPort)->toBeFalse();
});

test('scheme-less domain input gets scheme prepended in EditDomain flow', function () {
    $domain = 'git.example.com:80';
    if (! str_starts_with($domain, 'http://') && ! str_starts_with($domain, 'https://')) {
        $domain = 'http://'.$domain;
    }
    $url = Url::fromString($domain, ['http', 'https']);

    expect($url->getScheme())->toBe('http')
        ->and($url->getHost())->toBe('git.example.com')
        ->and($url->getPort())->toBe(80)
        ->and($url->__toString())->toBe('http://git.example.com:80');
});

test('previously mangled URL //:80host is rejected by Url::fromString', function () {
    // Verify the mangled format is indeed invalid
    expect(fn () => Url::fromString('//:80git.example.com'))
        ->toThrow(\Spatie\Url\Exceptions\InvalidArgument::class);
});

test('COOLIFY_URL and COOLIFY_FQDN extraction works correctly with ports', function () {
    // Test the pattern used for COOLIFY_URL env var
    $fqdn = 'http://git.example.com:80';
    $parsed = Url::fromString(ensureUrlHasScheme($fqdn));
    $coolifyUrl = $parsed->getScheme().'://'.$parsed->getHost();
    $coolifyFqdn = $parsed->getHost();

    expect($coolifyUrl)->toBe('http://git.example.com')
        ->and($coolifyFqdn)->toBe('git.example.com');
});
