<?php

/**
 * Regression tests for FQDN port handling.
 *
 * Covers the reported bug where URLs like http://git.example.com:80
 * were corrupted during COOLIFY_URL/COOLIFY_FQDN generation,
 * and ensures port insertion works correctly when paths are present.
 */
use Spatie\Url\Url;

test('getFqdnWithoutPort strips port from URL', function () {
    expect(getFqdnWithoutPort('http://git.example.com:80'))->toBe('http://git.example.com');
    expect(getFqdnWithoutPort('https://app.example.com:8443'))->toBe('https://app.example.com');
    expect(getFqdnWithoutPort('http://localhost:3000'))->toBe('http://localhost');
});

test('getFqdnWithoutPort preserves URL without port', function () {
    expect(getFqdnWithoutPort('http://git.example.com'))->toBe('http://git.example.com');
    expect(getFqdnWithoutPort('https://app.example.com'))->toBe('https://app.example.com');
});

test('getFqdnWithoutPort preserves path when stripping port', function () {
    expect(getFqdnWithoutPort('http://git.example.com:80/api/v1'))->toBe('http://git.example.com/api/v1');
    expect(getFqdnWithoutPort('https://app.example.com:443/dashboard'))->toBe('https://app.example.com/dashboard');
});

test('addPortToUrl inserts port correctly for simple URLs', function () {
    expect(addPortToUrl('https://app.example.com', 8080))->toBe('https://app.example.com:8080');
    expect(addPortToUrl('http://localhost', 3000))->toBe('http://localhost:3000');
});

test('addPortToUrl inserts port before path', function () {
    expect(addPortToUrl('https://app.example.com/dashboard', 8080))->toBe('https://app.example.com:8080/dashboard');
    expect(addPortToUrl('http://git.example.com/api/v1', 3000))->toBe('http://git.example.com:3000/api/v1');
});

test('addPortToUrl replaces existing port', function () {
    expect(addPortToUrl('https://app.example.com:443', 8080))->toBe('https://app.example.com:8080');
});

test('original bug case: http://git.example.com:80 roundtrip', function () {
    // The reported bug: FQDN http://git.example.com:80 was corrupted
    $fqdn = 'http://git.example.com:80';

    // Step 1: strip port (as done in COOLIFY_URL generation)
    $withoutPort = getFqdnWithoutPort($fqdn);
    expect($withoutPort)->toBe('http://git.example.com');

    // Step 2: re-add port (as done in service parser for urlWithPort)
    $withPort = addPortToUrl($withoutPort, 80);
    expect($withPort)->toBe('http://git.example.com:80');
});

test('service parser port insertion with path does not corrupt URL', function () {
    // Simulates the serviceParser flow: fqdn has path, port added later
    $savedFqdn = 'https://service.example.com:8080/v1/realtime';

    $fqdn = getFqdnWithoutPort($savedFqdn);
    expect($fqdn)->toBe('https://service.example.com/v1/realtime');

    $url = $fqdn;
    $fqdnValueForEnv = str($fqdn)->after('://')->value();
    expect($fqdnValueForEnv)->toBe('service.example.com/v1/realtime');

    $port = 8080;
    $urlWithPort = addPortToUrl($url, $port);
    expect($urlWithPort)->toBe('https://service.example.com:8080/v1/realtime');

    $fqdnValueForEnvWithPort = str(addPortToUrl("http://$fqdnValueForEnv", $port))->after('://')->value();
    expect($fqdnValueForEnvWithPort)->toBe('service.example.com:8080/v1/realtime');
});
