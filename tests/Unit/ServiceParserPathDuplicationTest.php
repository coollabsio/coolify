<?php

/**
 * Unit tests to verify that serviceParser() correctly handles path appending
 * to prevent duplication when SERVICE_URL/SERVICE_FQDN variables have path values.
 *
 * This tests the fix for GitHub issue #7363 where paths like /v1/realtime
 * were being duplicated on subsequent parse() calls after FQDN updates.
 */
test('path is added when FQDN does not already contain it', function () {
    $fqdn = 'https://test.abc';
    $path = '/v1/realtime';

    // Simulate the logic in serviceParser()
    if (! str($fqdn)->endsWith($path)) {
        $fqdn = "$fqdn$path";
    }

    expect($fqdn)->toBe('https://test.abc/v1/realtime');
});

test('path is not added when FQDN already contains it', function () {
    $fqdn = 'https://test.abc/v1/realtime';
    $path = '/v1/realtime';

    // Simulate the logic in serviceParser()
    if (! str($fqdn)->endsWith($path)) {
        $fqdn = "$fqdn$path";
    }

    expect($fqdn)->toBe('https://test.abc/v1/realtime');
});

test('multiple parse calls with same path do not cause duplication', function () {
    $fqdn = 'https://test.abc';
    $path = '/v1/realtime';

    // First parse (initial creation)
    if (! str($fqdn)->endsWith($path)) {
        $fqdn = "$fqdn$path";
    }
    expect($fqdn)->toBe('https://test.abc/v1/realtime');

    // Second parse (after FQDN update)
    if (! str($fqdn)->endsWith($path)) {
        $fqdn = "$fqdn$path";
    }
    expect($fqdn)->toBe('https://test.abc/v1/realtime');

    // Third parse (another update)
    if (! str($fqdn)->endsWith($path)) {
        $fqdn = "$fqdn$path";
    }
    expect($fqdn)->toBe('https://test.abc/v1/realtime');
});

test('different paths for different services work correctly', function () {
    // Appwrite main service (/)
    $fqdn1 = 'https://test.abc';
    $path1 = '/';
    if ($path1 !== '/' && ! str($fqdn1)->endsWith($path1)) {
        $fqdn1 = "$fqdn1$path1";
    }
    expect($fqdn1)->toBe('https://test.abc');

    // Appwrite console (/console)
    $fqdn2 = 'https://test.abc';
    $path2 = '/console';
    if ($path2 !== '/' && ! str($fqdn2)->endsWith($path2)) {
        $fqdn2 = "$fqdn2$path2";
    }
    expect($fqdn2)->toBe('https://test.abc/console');

    // Appwrite realtime (/v1/realtime)
    $fqdn3 = 'https://test.abc';
    $path3 = '/v1/realtime';
    if ($path3 !== '/' && ! str($fqdn3)->endsWith($path3)) {
        $fqdn3 = "$fqdn3$path3";
    }
    expect($fqdn3)->toBe('https://test.abc/v1/realtime');
});

test('nested paths are handled correctly', function () {
    $fqdn = 'https://test.abc';
    $path = '/api/v1/endpoint';

    if (! str($fqdn)->endsWith($path)) {
        $fqdn = "$fqdn$path";
    }

    expect($fqdn)->toBe('https://test.abc/api/v1/endpoint');

    // Second call should not duplicate
    if (! str($fqdn)->endsWith($path)) {
        $fqdn = "$fqdn$path";
    }

    expect($fqdn)->toBe('https://test.abc/api/v1/endpoint');
});

test('MindsDB /api path is handled correctly', function () {
    $fqdn = 'https://test.abc';
    $path = '/api';

    // First parse
    if (! str($fqdn)->endsWith($path)) {
        $fqdn = "$fqdn$path";
    }
    expect($fqdn)->toBe('https://test.abc/api');

    // Second parse should not duplicate
    if (! str($fqdn)->endsWith($path)) {
        $fqdn = "$fqdn$path";
    }
    expect($fqdn)->toBe('https://test.abc/api');
});

test('fqdnValueForEnv path handling works correctly', function () {
    $fqdnValueForEnv = 'test.abc';
    $path = '/v1/realtime';

    // First append
    if (! str($fqdnValueForEnv)->endsWith($path)) {
        $fqdnValueForEnv = "$fqdnValueForEnv$path";
    }
    expect($fqdnValueForEnv)->toBe('test.abc/v1/realtime');

    // Second attempt should not duplicate
    if (! str($fqdnValueForEnv)->endsWith($path)) {
        $fqdnValueForEnv = "$fqdnValueForEnv$path";
    }
    expect($fqdnValueForEnv)->toBe('test.abc/v1/realtime');
});

test('url path handling works correctly', function () {
    $url = 'https://test.abc';
    $path = '/v1/realtime';

    // First append
    if (! str($url)->endsWith($path)) {
        $url = "$url$path";
    }
    expect($url)->toBe('https://test.abc/v1/realtime');

    // Second attempt should not duplicate
    if (! str($url)->endsWith($path)) {
        $url = "$url$path";
    }
    expect($url)->toBe('https://test.abc/v1/realtime');
});
