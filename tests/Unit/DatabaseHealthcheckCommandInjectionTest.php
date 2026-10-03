<?php

/**
 * Regression tests for database healthcheck command injection.
 *
 * Docker CMD-SHELL healthchecks pass the string to /bin/sh -c, enabling command injection
 * via user-controlled DB username/password/database fields. The fix converts all affected
 * healthchecks to CMD exec-form arrays, which bypass the shell entirely.
 */

// ─── PostgreSQL ──────────────────────────────────────────────────────────────

test('postgresql healthcheck uses CMD exec-form, not CMD-SHELL', function () {
    $source = file_get_contents(__DIR__.'/../../app/Actions/Database/StartPostgresql.php');

    expect($source)->not->toContain('CMD-SHELL');
    expect($source)->toContain("'CMD', 'psql'");
});

// ─── KeyDB ────────────────────────────────────────────────────────────────────

test('keydb healthcheck uses CMD exec-form, not a CMD-SHELL string', function () {
    $source = file_get_contents(__DIR__.'/../../app/Actions/Database/StartKeydb.php');

    expect($source)->not->toContain('CMD-SHELL');
    expect($source)->toContain("'CMD', 'keydb-cli'");
});

// ─── Dragonfly ────────────────────────────────────────────────────────────────

test('dragonfly healthcheck uses CMD exec-form, not a CMD-SHELL string', function () {
    $source = file_get_contents(__DIR__.'/../../app/Actions/Database/StartDragonfly.php');

    expect($source)->not->toContain('CMD-SHELL');
    expect($source)->toContain("'CMD', 'redis-cli'");
});

// ─── Redis ────────────────────────────────────────────────────────────────────

test('redis healthcheck uses CMD exec-form, not a CMD-SHELL string', function () {
    $source = file_get_contents(__DIR__.'/../../app/Actions/Database/StartRedis.php');

    expect($source)->not->toContain('CMD-SHELL');
    expect($source)->toMatch("/'CMD',\s+'redis-cli',\s+'ping'/");
});

// ─── ClickHouse ───────────────────────────────────────────────────────────────

test('clickhouse healthcheck uses CMD exec-form, not a CMD-SHELL string', function () {
    $source = file_get_contents(__DIR__.'/../../app/Actions/Database/StartClickhouse.php');

    expect($source)->not->toContain('CMD-SHELL');
    expect($source)->toContain("'CMD', 'clickhouse-client'");
});

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
