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

// --- MongoDB collection name validation tests ---

test('mongodb collection name rejects command substitution injection', function () {
    expect(fn () => validateShellSafePath('$(touch /tmp/pwned)', 'collection name'))
        ->toThrow(Exception::class);
});

test('mongodb collection name rejects backtick injection', function () {
    expect(fn () => validateShellSafePath('`id > /tmp/pwned`', 'collection name'))
        ->toThrow(Exception::class);
});

test('mongodb collection name rejects semicolon injection', function () {
    expect(fn () => validateShellSafePath('col1; rm -rf /', 'collection name'))
        ->toThrow(Exception::class);
});

test('mongodb collection name rejects ampersand injection', function () {
    expect(fn () => validateShellSafePath('col1 & whoami', 'collection name'))
        ->toThrow(Exception::class);
});

test('mongodb collection name rejects redirect injection', function () {
    expect(fn () => validateShellSafePath('col1 > /tmp/pwned', 'collection name'))
        ->toThrow(Exception::class);
});

test('validateDatabasesBackupInput validates mongodb format with collection names', function () {
    // Valid MongoDB formats should pass
    expect(fn () => validateDatabasesBackupInput('mydb'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateDatabasesBackupInput('mydb:col1,col2'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateDatabasesBackupInput('db1:col1,col2|db2:col3'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateDatabasesBackupInput('all'))
        ->not->toThrow(Exception::class);
});

test('validateDatabasesBackupInput rejects injection in collection names', function () {
    // Command substitution in collection name
    expect(fn () => validateDatabasesBackupInput('mydb:$(touch /tmp/pwned)'))
        ->toThrow(Exception::class);

    // Backtick injection in collection name
    expect(fn () => validateDatabasesBackupInput('mydb:`id`'))
        ->toThrow(Exception::class);

    // Semicolon in collection name
    expect(fn () => validateDatabasesBackupInput('mydb:col1;rm -rf /'))
        ->toThrow(Exception::class);
});

test('validateDatabasesBackupInput rejects injection in database name within mongo format', function () {
    expect(fn () => validateDatabasesBackupInput('$(whoami):col1,col2'))
        ->toThrow(Exception::class);
});

// --- Credential escaping tests for database backup commands ---

test('escapeshellarg neutralizes command injection in postgres password', function () {
    $maliciousPassword = '"; rm -rf / #';
    $escaped = escapeshellarg($maliciousPassword);

    // The escaped value must be a single shell token that cannot break out
    expect($escaped)->not->toContain("\n");
    expect($escaped)->toBe("'\"; rm -rf / #'");
    // When used in: -e PGPASSWORD=<escaped>, the shell sees one token
    $command = 'docker exec -e PGPASSWORD='.$escaped.' container pg_dump';
    expect($command)->toContain("PGPASSWORD='");
    expect($command)->not->toContain('PGPASSWORD=""');
});

test('escapeshellarg neutralizes command injection in postgres username', function () {
    $maliciousUser = 'admin$(whoami)';
    $escaped = escapeshellarg($maliciousUser);

    expect($escaped)->toBe("'admin\$(whoami)'");
    $command = "docker exec container pg_dump --username $escaped";
    // The $() should be inside single quotes, preventing execution
    expect($command)->toContain("--username 'admin\$(whoami)'");
});

test('escapeshellarg neutralizes command injection in mysql password', function () {
    $maliciousPassword = 'pass" && curl http://evil.com #';
    $escaped = escapeshellarg($maliciousPassword);

    $command = "docker exec container mysqldump -u root -p$escaped db";
    // The password must be wrapped in single quotes
    expect($command)->toContain("-p'pass\" && curl http://evil.com #'");
});

test('escapeshellarg neutralizes command injection in mariadb password', function () {
    $maliciousPassword = "pass'; whoami; echo '";
    $escaped = escapeshellarg($maliciousPassword);

    // Single quotes in the value get escaped as '\''
    expect($escaped)->toBe("'pass'\\'''; whoami; echo '\\'''");
    $command = "docker exec container mariadb-dump -u root -p$escaped db";
    // Verify the command doesn't contain an unescaped semicolon outside quotes
    expect($command)->toContain("-p'pass'");
});

test('rawurlencode neutralizes shell injection in mongodb URI credentials', function () {
    $maliciousUser = 'admin";$(whoami)';
    $maliciousPass = 'pass@evil.com/admin?authSource=admin&rm -rf /';

    $encodedUser = rawurlencode($maliciousUser);
    $encodedPass = rawurlencode($maliciousPass);
    $url = "mongodb://{$encodedUser}:{$encodedPass}@container:27017";

    // Special characters should be percent-encoded
    expect($encodedUser)->not->toContain('"');
    expect($encodedUser)->not->toContain('$');
    expect($encodedUser)->not->toContain('(');
    expect($encodedPass)->not->toContain('@');
    expect($encodedPass)->not->toContain('/');
    expect($encodedPass)->not->toContain('?');
    expect($encodedPass)->not->toContain('&');

    // The URL should have exactly one @ (the delimiter) and the credentials percent-encoded
    $atCount = substr_count($url, '@');
    expect($atCount)->toBe(1);
});

test('escapeshellarg on mongodb URI prevents shell breakout', function () {
    // Even if internal_db_url contains malicious content, escapeshellarg wraps it safely
    $maliciousUrl = 'mongodb://admin:pass@host:27017" && curl http://evil.com #';
    $escaped = escapeshellarg($maliciousUrl);

    $command = "docker exec container mongodump --uri=$escaped --gzip --archive > /backup";
    // The entire URI must be inside single quotes
    expect($command)->toContain("--uri='mongodb://admin:pass@host:27017");
    expect($command)->toContain("evil.com #'");
    // No unescaped double quotes that could break the command
    expect(substr_count($command, "'"))->toBeGreaterThanOrEqual(2);
});
