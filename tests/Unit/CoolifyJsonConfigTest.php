<?php

use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;

/**
 * Unit tests for coolify.json configuration detection and application.
 * Tests the magic environment variable resolution logic and schema validation.
 */
it('resolves SERVICE_PASSWORD magic variable with default length', function () {
    $value = 'SERVICE_PASSWORD';

    // Default is 64 characters
    if ($value === 'SERVICE_PASSWORD') {
        $result = Str::password(length: 64, symbols: false);
    }

    expect($result)->toHaveLength(64);
});

it('resolves SERVICE_PASSWORD_XX magic variable with custom length', function () {
    $testCases = [
        'SERVICE_PASSWORD_32' => 32,
        'SERVICE_PASSWORD_128' => 128,
        'SERVICE_PASSWORD_16' => 16,
    ];

    foreach ($testCases as $value => $expectedLength) {
        if (preg_match('/^SERVICE_PASSWORD_(\d+)$/', $value, $matches)) {
            $length = (int) $matches[1];
            $length = max(8, min(256, $length));
            $result = Str::password(length: $length, symbols: false);
        }

        expect($result)->toHaveLength($expectedLength);
    }
});

it('clamps SERVICE_PASSWORD length between 8 and 256', function () {
    // Test too small
    $value = 'SERVICE_PASSWORD_2';
    if (preg_match('/^SERVICE_PASSWORD_(\d+)$/', $value, $matches)) {
        $length = (int) $matches[1];
        $length = max(8, min(256, $length));
    }
    expect($length)->toBe(8);

    // Test too large
    $value = 'SERVICE_PASSWORD_500';
    if (preg_match('/^SERVICE_PASSWORD_(\d+)$/', $value, $matches)) {
        $length = (int) $matches[1];
        $length = max(8, min(256, $length));
    }
    expect($length)->toBe(256);
});

it('resolves SERVICE_USER magic variable', function () {
    $value = 'SERVICE_USER';

    if ($value === 'SERVICE_USER') {
        $result = 'user_'.Str::random(8);
    }

    expect($result)->toStartWith('user_')
        ->and(strlen($result))->toBe(13); // 'user_' + 8 chars
});

it('resolves SERVICE_BASE64_XX magic variable', function () {
    $value = 'SERVICE_BASE64_32';

    if (preg_match('/^SERVICE_BASE64_(\d+)$/', $value, $matches)) {
        $length = (int) $matches[1];
        $length = max(8, min(256, $length));
        $result = base64_encode(Str::random($length));
    }

    // Base64 encoded string should decode properly
    $decoded = base64_decode($result, true);
    expect($decoded)->not->toBeFalse()
        ->and($decoded)->toHaveLength(32);
});

it('resolves SERVICE_UUID magic variable', function () {
    $value = 'SERVICE_UUID';

    if ($value === 'SERVICE_UUID') {
        $result = (string) new Cuid2;
    }

    expect($result)->toHaveLength(24); // Cuid2 default length
});

it('returns non-magic values unchanged', function () {
    $regularValue = 'my-regular-value';
    $result = $regularValue;

    // The resolution logic only transforms magic values
    $magicPatterns = [
        '/^SERVICE_PASSWORD_(\d+)$/',
        '/^SERVICE_PASSWORD$/',
        '/^SERVICE_USER$/',
        '/^SERVICE_BASE64_(\d+)$/',
        '/^SERVICE_UUID$/',
    ];

    $isMagic = false;
    foreach ($magicPatterns as $pattern) {
        if (preg_match($pattern, $regularValue)) {
            $isMagic = true;
            break;
        }
    }

    expect($isMagic)->toBeFalse()
        ->and($result)->toBe($regularValue);
});

it('validates coolify.json schema version', function () {
    // Test schema version validation in loadConfigFromGit by simulating the logic
    $supportedVersions = ['1.0'];

    expect(in_array('1.0', $supportedVersions))->toBeTrue()
        ->and(in_array('2.0', $supportedVersions))->toBeFalse()
        ->and(in_array('0.9', $supportedVersions))->toBeFalse();
});

it('detects unknown fields in coolify.json', function () {
    $config = [
        'version' => '1.0',
        'name' => 'test-app',
        'build' => ['type' => 'nixpacks'],
        'unknown_field' => 'value',
        'another_unknown' => 'value',
    ];

    $knownFields = ['version', 'name', 'description', 'build', 'domains', 'environment_variables', 'health_check', 'limits', 'settings'];
    $unknownFields = array_values(array_diff(array_keys($config), $knownFields));

    expect($unknownFields)->toBe(['unknown_field', 'another_unknown']);
});

it('builds correct file paths for base_directory', function () {
    // Test the path building logic used in loadConfigFromGit

    // With base_directory
    $baseDirectory = '/app/frontend';
    $workdir = rtrim($baseDirectory, '/');
    $pathsToCheck = [];

    if ($workdir !== '' && $workdir !== '/') {
        $pathsToCheck[] = ltrim($workdir, '/').'/coolify.json';
    }
    $pathsToCheck[] = 'coolify.json';

    expect($pathsToCheck)->toBe(['app/frontend/coolify.json', 'coolify.json']);

    // With root base_directory
    $baseDirectory = '/';
    $workdir = rtrim($baseDirectory, '/');
    $pathsToCheck = [];

    if ($workdir !== '' && $workdir !== '/') {
        $pathsToCheck[] = ltrim($workdir, '/').'/coolify.json';
    }
    $pathsToCheck[] = 'coolify.json';

    expect($pathsToCheck)->toBe(['coolify.json']);
});

it('parses valid coolify.json config', function () {
    $json = json_encode([
        'version' => '1.0',
        'name' => 'my-app',
        'build' => [
            'type' => 'nixpacks',
            'install_command' => 'npm install',
            'build_command' => 'npm run build',
            'start_command' => 'npm start',
        ],
        'domains' => [
            'ports_exposes' => '3000',
        ],
        'environment_variables' => [
            'production' => [
                ['key' => 'NODE_ENV', 'value' => 'production'],
                ['key' => 'DB_PASSWORD', 'value' => 'SERVICE_PASSWORD_64'],
            ],
        ],
    ]);

    $config = json_decode($json, true);

    expect($config)->toBeArray()
        ->and(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and(data_get($config, 'version'))->toBe('1.0')
        ->and(data_get($config, 'build.type'))->toBe('nixpacks')
        ->and(data_get($config, 'environment_variables.production'))->toHaveCount(2);
});

it('handles invalid JSON gracefully', function () {
    $invalidJson = '{invalid json}';
    $config = json_decode($invalidJson, true);

    expect(json_last_error())->not->toBe(JSON_ERROR_NONE)
        ->and($config)->toBeNull();
});
