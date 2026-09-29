<?php

use App\Jobs\DatabaseBackupJob;
use App\Support\ValidationPatterns;

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

test('backup shell commands do not interpolate raw container names or backup paths', function () {
    $source = file_get_contents(__DIR__.'/../../app/Jobs/DatabaseBackupJob.php');
    preg_match_all('/^.*(?:docker exec|mkdir -p |du -b |docker run -d).*$/m', $source, $matches);

    expect($matches[0])->not->toBeEmpty();
    foreach ($matches[0] as $command) {
        $quotedArgumentsRemoved = preg_replace('/escapeshellarg\(\$this->(?:container_name|backup_dir|backup_location)\)/', '', $command);
        expect($quotedArgumentsRemoved)->not->toMatch('/\$this->(?:container_name|backup_dir|backup_location)\b/');
    }
});

test('backup job accepts supported service container names only', function () {
    expect(ValidationPatterns::isValidContainerName('db-name_service.1-uuid'))->toBeTrue()
        ->and(ValidationPatterns::isValidContainerName('db name-uuid'))->toBeFalse()
        ->and(ValidationPatterns::isValidContainerName("db\nname-uuid"))->toBeFalse()
        ->and(ValidationPatterns::isValidContainerName("db-uuid\n"))->toBeFalse();

    $source = file_get_contents(__DIR__.'/../../app/Jobs/DatabaseBackupJob.php');
    expect($source)->toContain('ValidationPatterns::isValidContainerName($this->container_name)');
});

test('service restart commands do not interpolate raw container names', function () {
    foreach (['ServiceDatabase.php', 'ServiceApplication.php'] as $model) {
        $source = file_get_contents(__DIR__.'/../../app/Models/'.$model);
        expect($source)->not->toContain('docker restart {$container_id}');
    }
});

test('backup filenames remove shell and path separators from database names', function () {
    $job = (new ReflectionClass(DatabaseBackupJob::class))->newInstanceWithoutConstructor();
    $filenamePart = (new ReflectionClass($job))->getMethod('backupFilenamePart');

    expect($filenamePart->invoke($job, 'safe_db-1'))->toBe('safe_db-1')
        ->and($filenamePart->invoke($job, '../db name'))->toBe('..-db-name');

    $source = file_get_contents(__DIR__.'/../../app/Jobs/DatabaseBackupJob.php');
    expect(substr_count($source, '$this->backupFilenamePart('))->toBe(4);
});
