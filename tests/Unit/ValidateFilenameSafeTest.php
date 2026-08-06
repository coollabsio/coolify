<?php

test('allows plain filenames without special characters', function () {
    $validNames = [
        'init.sql',
        '01_schema.sql',
        'setup-db.sql',
        'create_test_db.sql',
        'init-script.sh',
        'UPPERCASE.SQL',
        'mixed_Case-File.sql',
        'file123.sql',
        'a',
    ];

    foreach ($validNames as $name) {
        expect(fn () => validateFilenameSafe($name, 'init script filename'))
            ->not->toThrow(Exception::class, "Expected '{$name}' to pass");
    }
});

test('rejects path traversal with ../', function () {
    expect(fn () => validateFilenameSafe('../../../etc/cron.d/pwn', 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects path traversal with .. alone', function () {
    expect(fn () => validateFilenameSafe('..', 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects path traversal embedded in filename', function () {
    expect(fn () => validateFilenameSafe('foo..bar', 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects forward slash directory separator', function () {
    expect(fn () => validateFilenameSafe('foo/bar.sql', 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects backslash directory separator', function () {
    expect(fn () => validateFilenameSafe('foo\\bar.sql', 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects absolute path starting with slash', function () {
    expect(fn () => validateFilenameSafe('/etc/passwd', 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects absolute Windows-style path', function () {
    expect(fn () => validateFilenameSafe('C:\\Windows\\System32\\cmd.exe', 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects null byte injection', function () {
    expect(fn () => validateFilenameSafe("init.sql\0../../etc/passwd", 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects shell command substitution (inherits from validateShellSafePath)', function () {
    expect(fn () => validateFilenameSafe('$(whoami).sql', 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects backtick command substitution', function () {
    expect(fn () => validateFilenameSafe('`id`.sql', 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects semicolon command separator', function () {
    expect(fn () => validateFilenameSafe('init.sql;rm -rf /', 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects pipe operator', function () {
    expect(fn () => validateFilenameSafe('init.sql|whoami', 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects redirect operators', function () {
    expect(fn () => validateFilenameSafe('init.sql>/etc/passwd', 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects mixed traversal and shell injection', function () {
    expect(fn () => validateFilenameSafe('../etc/cron.d/$(id)', 'init script filename'))
        ->toThrow(Exception::class);
});

test('error message contains context string', function () {
    try {
        validateFilenameSafe('../evil', 'init script filename');
        expect(false)->toBeTrue('Should have thrown');
    } catch (Exception $e) {
        expect($e->getMessage())->toContain('init script filename');
    }
});

test('handles empty string without throwing', function () {
    expect(fn () => validateFilenameSafe('', 'init script filename'))
        ->not->toThrow(Exception::class);
});

test('rejects whitespace inside filename (would split into extra tee arg)', function () {
    expect(fn () => validateFilenameSafe('foo bar.sql', 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects glob wildcards', function () {
    expect(fn () => validateFilenameSafe('init*.sql', 'init script filename'))
        ->toThrow(Exception::class);

    expect(fn () => validateFilenameSafe('init?.sql', 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects glob character class brackets', function () {
    expect(fn () => validateFilenameSafe('init[abc].sql', 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects tilde expansion', function () {
    expect(fn () => validateFilenameSafe('~/evil.sql', 'init script filename'))
        ->toThrow(Exception::class);

    expect(fn () => validateFilenameSafe('~root', 'init script filename'))
        ->toThrow(Exception::class);
});

test('rejects single and double quotes', function () {
    expect(fn () => validateFilenameSafe("foo'bar.sql", 'init script filename'))
        ->toThrow(Exception::class);

    expect(fn () => validateFilenameSafe('foo"bar.sql', 'init script filename'))
        ->toThrow(Exception::class);
});
