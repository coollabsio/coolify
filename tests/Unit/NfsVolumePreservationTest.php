<?php

use Symfony\Component\Yaml\Yaml;

test('non-sequential collection keys produce invalid YAML mapping for volumes', function () {
    $volumesParsed = collect([]);
    $volumesParsed->put(0, 'uuid_test-cache:/cache');
    $volumesParsed->put(3, '/data/coolify/applications/uuid/configs/config.yml:/config.yml');
    $volumesParsed->put(4, '/etc/localtime:/etc/localtime:ro');

    $payload = ['volumes' => $volumesParsed->toArray()];
    $yaml = Yaml::dump($payload, 10, 2);

    expect($yaml)->toContain('0:');
    expect($yaml)->not->toContain('- uuid_test-cache');
});

test('sequential collection keys produce valid YAML list for volumes', function () {
    $volumesParsed = collect([]);
    $volumesParsed->put(0, 'uuid_test-cache:/cache');
    $volumesParsed->put(3, '/data/coolify/applications/uuid/configs/config.yml:/config.yml');
    $volumesParsed->put(4, '/etc/localtime:/etc/localtime:ro');

    $volumesParsed = $volumesParsed->values();

    $payload = ['volumes' => $volumesParsed->toArray()];
    $yaml = Yaml::dump($payload, 10, 2);

    expect($yaml)->toContain("- 'uuid_test-cache:/cache'");
    expect($yaml)->toContain("- '/data/coolify/applications/uuid/configs/config.yml:/config.yml'");
    expect($yaml)->toContain("- '/etc/localtime:/etc/localtime:ro'");
    expect($yaml)->not->toContain('0:');
});

test('NFS volumes are preserved when mixed with persistent and bind volumes', function () {
    $volumes = [
        'test_cache:/cache',
        'test_stores:/stores',
        'test_backups:/backups',
        './configs/config.yml:/config.yml',
        '/etc/localtime:/etc/localtime:ro',
    ];

    $topLevelVolumes = collect([
        'test_cache' => ['driver' => 'local'],
        'test_stores' => ['driver_opts' => ['type' => 'nfs', 'o' => 'addr=192.168.68.61', 'device' => ':/mnt/stores']],
        'test_backups' => ['driver_opts' => ['type' => 'nfs', 'o' => 'addr=192.168.68.61', 'device' => ':/mnt/backups']],
    ]);

    $volumesParsed = collect([]);

    foreach ($volumes as $index => $volume) {
        $parsed = parseDockerVolumeString($volume);
        $source = $parsed['source'];

        if (sourceIsLocal($source)) {
            $volumesParsed->put($index, 'transformed_bind_'.$index);
        } else {
            if ($topLevelVolumes->has($source->value())) {
                $temp = $topLevelVolumes->get($source->value());
                if (data_get($temp, 'driver_opts.type') === 'cifs' || data_get($temp, 'driver_opts.type') === 'nfs') {
                    $volumesParsed->put($index, $volume);

                    continue;
                }
            }
            $volumesParsed->put($index, 'uuid_transformed_'.$source->value().':'.$parsed['target']->value());
        }
    }

    $volumesParsed = $volumesParsed->values();

    expect($volumesParsed)->toHaveCount(5);
    expect($volumesParsed->contains('test_stores:/stores'))->toBeTrue();
    expect($volumesParsed->contains('test_backups:/backups'))->toBeTrue();
    expect(array_keys($volumesParsed->toArray()))->toBe([0, 1, 2, 3, 4]);

    $yaml = Yaml::dump(['volumes' => $volumesParsed->toArray()], 10, 2);
    expect($yaml)->toContain("- 'test_stores:/stores'");
    expect($yaml)->toContain("- 'test_backups:/backups'");
    expect($yaml)->not->toContain('0:');
    expect($yaml)->not->toContain('1:');
});

test('CIFS volumes are preserved the same way as NFS volumes', function () {
    $topLevelVolumes = collect([
        'cifs_share' => ['driver_opts' => ['type' => 'cifs', 'o' => 'addr=192.168.1.1', 'device' => '//server/share']],
    ]);

    $volumesParsed = collect([]);
    $volume = 'cifs_share:/data';
    $parsed = parseDockerVolumeString($volume);
    $source = $parsed['source'];

    if ($topLevelVolumes->has($source->value())) {
        $temp = $topLevelVolumes->get($source->value());
        if (data_get($temp, 'driver_opts.type') === 'cifs' || data_get($temp, 'driver_opts.type') === 'nfs') {
            $volumesParsed->put(0, $volume);
        }
    }

    $volumesParsed = $volumesParsed->values();

    expect($volumesParsed)->toHaveCount(1);
    expect($volumesParsed->first())->toBe('cifs_share:/data');
});
