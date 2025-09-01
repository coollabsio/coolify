<?php

test('parses simple volume mappings', function () {
    // Simple named volume
    $result = parseDockerVolumeString('gitea:/data');
    expect($result['source']->value())->toBe('gitea');
    expect($result['target']->value())->toBe('/data');
    expect($result['mode'])->toBeNull();

    // Simple bind mount
    $result = parseDockerVolumeString('./data:/app/data');
    expect($result['source']->value())->toBe('./data');
    expect($result['target']->value())->toBe('/app/data');
    expect($result['mode'])->toBeNull();

    // Absolute path bind mount
    $result = parseDockerVolumeString('/var/lib/data:/data');
    expect($result['source']->value())->toBe('/var/lib/data');
    expect($result['target']->value())->toBe('/data');
    expect($result['mode'])->toBeNull();
});

test('parses volumes with read-only mode', function () {
    // Named volume with ro mode
    $result = parseDockerVolumeString('gitea-localtime:/etc/localtime:ro');
    expect($result['source']->value())->toBe('gitea-localtime');
    expect($result['target']->value())->toBe('/etc/localtime');
    expect($result['mode']->value())->toBe('ro');

    // Bind mount with ro mode
    $result = parseDockerVolumeString('/etc/localtime:/etc/localtime:ro');
    expect($result['source']->value())->toBe('/etc/localtime');
    expect($result['target']->value())->toBe('/etc/localtime');
    expect($result['mode']->value())->toBe('ro');
});

test('parses volumes with other modes', function () {
    // Read-write mode
    $result = parseDockerVolumeString('data:/var/data:rw');
    expect($result['source']->value())->toBe('data');
    expect($result['target']->value())->toBe('/var/data');
    expect($result['mode']->value())->toBe('rw');

    // Z mode (SELinux)
    $result = parseDockerVolumeString('config:/etc/config:z');
    expect($result['source']->value())->toBe('config');
    expect($result['target']->value())->toBe('/etc/config');
    expect($result['mode']->value())->toBe('z');

    // Cached mode (macOS)
    $result = parseDockerVolumeString('./src:/app/src:cached');
    expect($result['source']->value())->toBe('./src');
    expect($result['target']->value())->toBe('/app/src');
    expect($result['mode']->value())->toBe('cached');

    // Delegated mode (macOS)
    $result = parseDockerVolumeString('./node_modules:/app/node_modules:delegated');
    expect($result['source']->value())->toBe('./node_modules');
    expect($result['target']->value())->toBe('/app/node_modules');
    expect($result['mode']->value())->toBe('delegated');
});

test('parses volumes with environment variables', function () {
    // Variable with default value
    $result = parseDockerVolumeString('${VOLUME_DB_PATH:-db}:/data/db');
    expect($result['source']->value())->toBe('db');
    expect($result['target']->value())->toBe('/data/db');
    expect($result['mode'])->toBeNull();

    // Variable without default value
    $result = parseDockerVolumeString('${VOLUME_PATH}:/data');
    expect($result['source']->value())->toBe('${VOLUME_PATH}');
    expect($result['target']->value())->toBe('/data');
    expect($result['mode'])->toBeNull();

    // Variable with empty default - keeps variable reference for env resolution
    $result = parseDockerVolumeString('${VOLUME_PATH:-}:/data');
    expect($result['source']->value())->toBe('${VOLUME_PATH}');
    expect($result['target']->value())->toBe('/data');
    expect($result['mode'])->toBeNull();

    // Variable with mode
    $result = parseDockerVolumeString('${DATA_PATH:-./data}:/app/data:ro');
    expect($result['source']->value())->toBe('./data');
    expect($result['target']->value())->toBe('/app/data');
    expect($result['mode']->value())->toBe('ro');
});

test('parses Windows paths', function () {
    // Windows absolute path
    $result = parseDockerVolumeString('C:/Users/data:/data');
    expect($result['source']->value())->toBe('C:/Users/data');
    expect($result['target']->value())->toBe('/data');
    expect($result['mode'])->toBeNull();

    // Windows path with mode
    $result = parseDockerVolumeString('D:/projects/app:/app:rw');
    expect($result['source']->value())->toBe('D:/projects/app');
    expect($result['target']->value())->toBe('/app');
    expect($result['mode']->value())->toBe('rw');

    // Windows path with spaces (should be quoted in real use)
    $result = parseDockerVolumeString('C:/Program Files/data:/data');
    expect($result['source']->value())->toBe('C:/Program Files/data');
    expect($result['target']->value())->toBe('/data');
    expect($result['mode'])->toBeNull();
});

test('parses edge cases', function () {
    // Volume name only (unusual but valid)
    $result = parseDockerVolumeString('myvolume');
    expect($result['source']->value())->toBe('myvolume');
    expect($result['target']->value())->toBe('myvolume');
    expect($result['mode'])->toBeNull();

    // Path with colon in target (not a mode)
    $result = parseDockerVolumeString('source:/path:8080');
    expect($result['source']->value())->toBe('source');
    expect($result['target']->value())->toBe('/path:8080');
    expect($result['mode'])->toBeNull();

    // Multiple colons in path (not Windows)
    $result = parseDockerVolumeString('data:/var/lib/docker:data:backup');
    expect($result['source']->value())->toBe('data');
    expect($result['target']->value())->toBe('/var/lib/docker:data:backup');
    expect($result['mode'])->toBeNull();
});

test('parses tmpfs and other special cases', function () {
    // Docker socket binding
    $result = parseDockerVolumeString('/var/run/docker.sock:/var/run/docker.sock');
    expect($result['source']->value())->toBe('/var/run/docker.sock');
    expect($result['target']->value())->toBe('/var/run/docker.sock');
    expect($result['mode'])->toBeNull();

    // Docker socket with mode
    $result = parseDockerVolumeString('/var/run/docker.sock:/var/run/docker.sock:ro');
    expect($result['source']->value())->toBe('/var/run/docker.sock');
    expect($result['target']->value())->toBe('/var/run/docker.sock');
    expect($result['mode']->value())->toBe('ro');

    // Tmp mount
    $result = parseDockerVolumeString('/tmp:/tmp');
    expect($result['source']->value())->toBe('/tmp');
    expect($result['target']->value())->toBe('/tmp');
    expect($result['mode'])->toBeNull();
});

test('handles whitespace correctly', function () {
    // Leading/trailing whitespace
    $result = parseDockerVolumeString('  data:/app/data  ');
    expect($result['source']->value())->toBe('data');
    expect($result['target']->value())->toBe('/app/data');
    expect($result['mode'])->toBeNull();

    // Whitespace with mode
    $result = parseDockerVolumeString('  ./config:/etc/config:ro  ');
    expect($result['source']->value())->toBe('./config');
    expect($result['target']->value())->toBe('/etc/config');
    expect($result['mode']->value())->toBe('ro');
});

test('parses all valid Docker volume modes', function () {
    $validModes = ['ro', 'rw', 'z', 'Z', 'rslave', 'rprivate', 'rshared',
        'slave', 'private', 'shared', 'cached', 'delegated', 'consistent'];

    foreach ($validModes as $mode) {
        $result = parseDockerVolumeString("volume:/data:$mode");
        expect($result['source']->value())->toBe('volume');
        expect($result['target']->value())->toBe('/data');
        expect($result['mode']->value())->toBe($mode);
    }
});

test('parses complex real-world examples', function () {
    // MongoDB volume with environment variable
    $result = parseDockerVolumeString('${VOLUME_DB_PATH:-./data/db}:/data/db');
    expect($result['source']->value())->toBe('./data/db');
    expect($result['target']->value())->toBe('/data/db');
    expect($result['mode'])->toBeNull();

    // Config file mount with read-only
    $result = parseDockerVolumeString('/home/user/app/config.yml:/app/config.yml:ro');
    expect($result['source']->value())->toBe('/home/user/app/config.yml');
    expect($result['target']->value())->toBe('/app/config.yml');
    expect($result['mode']->value())->toBe('ro');

    // Named volume with hyphens and underscores
    $result = parseDockerVolumeString('my-app_data_v2:/var/lib/app-data');
    expect($result['source']->value())->toBe('my-app_data_v2');
    expect($result['target']->value())->toBe('/var/lib/app-data');
    expect($result['mode'])->toBeNull();
});

test('preserves mode when reconstructing volume strings', function () {
    // Test cases that specifically verify mode preservation
    $testCases = [
        '/var/run/docker.sock:/var/run/docker.sock:ro' => ['source' => '/var/run/docker.sock', 'target' => '/var/run/docker.sock', 'mode' => 'ro'],
        '/etc/localtime:/etc/localtime:ro' => ['source' => '/etc/localtime', 'target' => '/etc/localtime', 'mode' => 'ro'],
        '/tmp:/tmp:rw' => ['source' => '/tmp', 'target' => '/tmp', 'mode' => 'rw'],
        'gitea-data:/data:ro' => ['source' => 'gitea-data', 'target' => '/data', 'mode' => 'ro'],
        './config:/app/config:cached' => ['source' => './config', 'target' => '/app/config', 'mode' => 'cached'],
        'volume:/data:delegated' => ['source' => 'volume', 'target' => '/data', 'mode' => 'delegated'],
    ];

    foreach ($testCases as $input => $expected) {
        $result = parseDockerVolumeString($input);

        // Verify parsing
        expect($result['source']->value())->toBe($expected['source']);
        expect($result['target']->value())->toBe($expected['target']);
        expect($result['mode']->value())->toBe($expected['mode']);

        // Verify reconstruction would preserve the mode
        $reconstructed = $result['source']->value().':'.$result['target']->value();
        if ($result['mode']) {
            $reconstructed .= ':'.$result['mode']->value();
        }
        expect($reconstructed)->toBe($input);
    }
});
