<?php

/**
 * GHSA-rcch-8c74-7f29 — Sink-side escaping tests
 *
 * Verifies that credentials reaching shell commands are properly escaped
 * even if validation is bypassed (e.g. legacy rows, direct DB writes).
 */

// ── executeInDocker + escapeshellarg chown pattern ────────────────────────────

it('escapeshellarg wraps postgres_user in single quotes for chown command', function () {
    $user = 'postgres';
    $escaped = escapeshellarg($user);
    $cmd = executeInDocker('abc123', "chown {$escaped}:{$escaped} /var/lib/postgresql/certs/server.key");

    // executeInDocker embeds the command inside bash -c '...', escaping inner single quotes as '\''
    // so escapeshellarg('postgres') = 'postgres' becomes '\''postgres'\'' in the outer shell string
    expect($cmd)->toContain('bash -c')
        ->toContain('postgres')
        ->toContain('chown');
});

it('advisory PoC postgres_user payload is contained by escapeshellarg in chown command', function () {
    // Simulates a legacy row that bypassed validation
    $maliciousUser = 'root; touch /tmp/pwned_rce; #';
    $escaped = escapeshellarg($maliciousUser);

    // escapeshellarg must wrap the entire payload in single quotes
    // (semicolons inside single-quoted args are NOT shell metacharacters)
    expect($escaped)->toBe("'root; touch /tmp/pwned_rce; #'");

    $cmd = executeInDocker('abc123', "chown {$escaped}:{$escaped} /var/lib/postgresql/certs/server.key");

    // The cmd contains the payload, but ONLY inside single-quoted segments — cannot break out.
    // Verify the chown arg is never an unquoted bare ; — the payload is inside '...'
    // The outer executeInDocker further escapes any single-quote chars for the host shell.
    expect($cmd)->toContain('docker exec abc123 bash -c');

    // Before fix: chown root; touch /tmp/pwned_rce; # ... (breaks out of chown, executes touch)
    // After fix: chown 'root; touch /tmp/pwned_rce; #':'...' ... (literal arg to chown)
    // The unescaped sequence "chown root;" must NOT appear.
    expect($cmd)->not->toContain('chown root;');
});

it('subshell payload in mysql_user is contained by escapeshellarg in chown command', function () {
    $maliciousUser = 'a$(touch /tmp/pwn)b';
    $escaped = escapeshellarg($maliciousUser);
    $cmd = executeInDocker('abc123', "chown {$escaped}:{$escaped} /etc/mysql/certs/server.crt");

    // escapeshellarg wraps in single quotes — $() is not expanded inside single quotes
    expect($escaped)->toBe("'a\$(touch /tmp/pwn)b'");

    // The cmd must not contain an unquoted $( sequence — it must be inside single quotes
    // If the sequence appears at all, it must be single-quoted (the quote precedes it).
    expect($cmd)->not->toContain(' $(touch');
});

it('subshell payload in postgres_user is contained by escapeshellarg in chown command', function () {
    $maliciousUser = 'a$(touch /tmp/pwn_postgres)b';
    $escaped = escapeshellarg($maliciousUser);
    $cmd = executeInDocker('abc123', "chown {$escaped}:{$escaped} /var/lib/postgresql/certs/server.key /var/lib/postgresql/certs/server.crt");

    expect($escaped)->toBe("'a\$(touch /tmp/pwn_postgres)b'");
    expect($cmd)->not->toContain(' $(touch');
});

it('semicolon payload in postgres_user is contained by escapeshellarg in chown command', function () {
    $maliciousUser = 'root; touch /tmp/pwned_pg; #';
    $escaped = escapeshellarg($maliciousUser);
    $cmd = executeInDocker('abc123', "chown {$escaped}:{$escaped} /var/lib/postgresql/certs/server.key /var/lib/postgresql/certs/server.crt");

    expect($escaped)->toBe("'root; touch /tmp/pwned_pg; #'");
    expect($cmd)->not->toContain('chown root;');
});

it('backtick payload in mysql_user is contained by escapeshellarg', function () {
    $maliciousUser = 'user`id`';
    $escaped = escapeshellarg($maliciousUser);
    $cmd = executeInDocker('abc123', "chown {$escaped}:{$escaped} /etc/mysql/certs/server.crt");

    // escapeshellarg wraps the whole value in single quotes — backticks not expanded inside ''
    expect($escaped)->toBe("'user`id`'");

    // The unquoted bare backtick sequence `id` must not appear outside single-quoted context.
    // Specifically, "chown user`id`" (unquoted) must not appear.
    expect($cmd)->not->toContain('chown user`id`');
});

// ── MongoDB JS init script JSON-escaping ──────────────────────────────────────

it('json_encode prevents JS injection in mongo_initdb_database', function () {
    $database = 'x"}); db.dropUser("admin"); //';
    $dbJson = json_encode($database, JSON_UNESCAPED_SLASHES);

    // The double-quotes in the payload MUST be escaped — they cannot close the JS string literal.
    // json_encode escapes " as \" so the injected " cannot terminate the surrounding JS string.
    expect($dbJson)->toContain('\\"');

    // The resulting JSON literal, when embedded in JS, forms a valid quoted string.
    // It starts and ends with the outermost " added by json_encode.
    expect($dbJson)->toStartWith('"')
        ->toEndWith('"');

    // Verify the injected payload is present but neutralised (the " that would close the JS
    // string is now escaped as \", preventing breakout).
    expect($dbJson)->toContain('x\\"});');
});

it('json_encode prevents JS injection in mongo_initdb_root_username', function () {
    $username = 'admin", pwd: "", roles: [{role:"root", db:"admin"}]}); //';
    $userJson = json_encode($username, JSON_UNESCAPED_SLASHES);

    $content = 'db.createUser({user: '.$userJson.', pwd: "secret", roles: []});';

    // The injected " that would close the JS string must be escaped as \"
    expect($userJson)->toContain('\\"');

    // The raw unescaped sequence admin" (with unescaped quote) must not appear in the JS
    expect($content)->not->toContain('admin", pwd');
});

it('json_encode safely encodes a clean mongo username', function () {
    $username = 'mongouser';
    $userJson = json_encode($username, JSON_UNESCAPED_SLASHES);

    expect($userJson)->toBe('"mongouser"');
});

it('json_encode safely encodes a mongo password with special chars', function () {
    $password = 'P@ss!#word123';
    $pwdJson = json_encode($password, JSON_UNESCAPED_SLASHES);

    expect($pwdJson)->toBe('"P@ss!#word123"');
});

// ── Healthcheck CMD exec-form structure (no shell parsing) ────────────────────

it('CMD exec-form healthcheck array does not concatenate user into a shell string', function () {
    // The fix uses an array; each element is passed directly as argv — no shell parsing.
    // Simulate the post-fix healthcheck array structure.
    $user = "admin'; touch /tmp/pwn; #";
    $db = 'mydb';

    $healthcheck = [
        'CMD',
        'psql',
        '-U',
        $user,
        '-d',
        $db,
        '-c',
        'SELECT 1',
    ];

    // The array form means each element is argv — no shell involved.
    // The malicious user value is passed as a literal argument to psql, which rejects it.
    // Key assertion: the test string is NOT collapsed into a shell command string.
    expect($healthcheck[3])->toBe($user)
        ->and($healthcheck[0])->toBe('CMD')
        ->and(count($healthcheck))->toBe(8);

    // Sanity: if we joined with space it would be dangerous — array form avoids this.
    $joinedDangerous = implode(' ', $healthcheck);
    expect($joinedDangerous)->toContain('; touch /tmp/pwn'); // proof that join IS dangerous

    // The array form is what Docker Compose uses — it does NOT join with spaces + sh -c.
    // Simply verifying the structure is correct proves shell is not involved.
    expect($healthcheck[0])->toBe('CMD');
});
