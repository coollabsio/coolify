<?php

/**
 * Database Backup Security Tests
 *
 * Tests to ensure database backup functionality is protected against
 * command injection attacks via malicious database names.
 *
 * Related Issues: #2 in security_issues.md
 * Related Files: app/Jobs/DatabaseBackupJob.php, app/Livewire/Project/Database/BackupEdit.php
 */
test('database backup rejects command injection in database name with command substitution', function () {
    expect(fn () => validateShellSafePath('test$(whoami)', 'database name'))
        ->toThrow(Exception::class);
});

test('database backup rejects command injection with semicolon separator', function () {
    expect(fn () => validateShellSafePath('test; rm -rf /', 'database name'))
        ->toThrow(Exception::class);
});

test('database backup rejects command injection with pipe operator', function () {
    expect(fn () => validateShellSafePath('test | cat /etc/passwd', 'database name'))
        ->toThrow(Exception::class);
});

test('database backup rejects command injection with backticks', function () {
    expect(fn () => validateShellSafePath('test`whoami`', 'database name'))
        ->toThrow(Exception::class);
});

test('database backup rejects command injection with ampersand', function () {
    expect(fn () => validateShellSafePath('test & whoami', 'database name'))
        ->toThrow(Exception::class);
});

test('database backup rejects command injection with redirect operators', function () {
    expect(fn () => validateShellSafePath('test > /tmp/pwned', 'database name'))
        ->toThrow(Exception::class);

    expect(fn () => validateShellSafePath('test < /etc/passwd', 'database name'))
        ->toThrow(Exception::class);
});

test('database backup rejects command injection with newlines', function () {
    expect(fn () => validateShellSafePath("test\nrm -rf /", 'database name'))
        ->toThrow(Exception::class);
});

test('database backup escapes shell arguments properly', function () {
    $database = "test'db";
    $escaped = escapeshellarg($database);

    expect($escaped)->toBe("'test'\\''db'");
});

test('database backup escapes shell arguments with double quotes', function () {
    $database = 'test"db';
    $escaped = escapeshellarg($database);

    expect($escaped)->toBe("'test\"db'");
});

test('database backup escapes shell arguments with spaces', function () {
    $database = 'test database';
    $escaped = escapeshellarg($database);

    expect($escaped)->toBe("'test database'");
});

test('database backup accepts legitimate database names', function () {
    expect(fn () => validateShellSafePath('postgres', 'database name'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateShellSafePath('my_database', 'database name'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateShellSafePath('db-prod', 'database name'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateShellSafePath('test123', 'database name'))
        ->not->toThrow(Exception::class);
});
