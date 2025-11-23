<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Unit tests to verify that custom volume drivers (CIFS, NFS, tmpfs, etc.) are preserved
 * in Docker Compose files when mixed with regular volumes.
 *
 * This test ensures that volumes with any driver_opts.type value
 * are not skipped during parsing and remain in the service's volumes array.
 */
it('ensures custom volume drivers are preserved instead of skipped', function () {
    // Read the parsers.php file to verify the fix
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Check that the old "continue" pattern has been replaced
    // Old pattern: if (data_get($temp, 'driver_opts.type') === 'cifs') { continue; }
    expect($parsersFile)
        ->not->toContain("if (data_get(\$temp, 'driver_opts.type') === 'cifs') {\n                            continue;")
        ->not->toContain("if (data_get(\$temp, 'driver_opts.type') === 'nfs') {\n                            continue;");

    // Check that the new generic preservation logic exists
    expect($parsersFile)
        ->toContain('$isCustomVolumeDriver = false')
        ->toContain("if (data_get(\$temp, 'driver_opts.type'))")
        ->toContain('// Preserve custom volume drivers as-is without renaming or creating LocalPersistentVolume');
});

it('verifies custom volume driver preservation logic exists in both parsing locations', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Count occurrences of the preservation logic - should appear twice (two locations)
    $preservationCount = substr_count($parsersFile, '// Preserve custom volume drivers as-is without renaming or creating LocalPersistentVolume');
    expect($preservationCount)->toBe(2, 'Custom volume driver preservation logic should exist in both parsing locations');
});

it('verifies SMB volume structure is preserved in YAML', function () {
    // Test that a compose file with SMB volumes maintains correct structure
    $composeWithSmb = <<<'YAML'
services:
  webserver:
    image: nginx:latest
    volumes:
      - mysmb:/smb
      - ./local:/data

volumes:
  mysmb:
    driver: local
    driver_opts:
      type: cifs
      o: username=user,password=pass,vers=3.0
      device: //192.168.1.1/sharename
YAML;

    $parsed = Yaml::parse($composeWithSmb);

    // Verify SMB volume is in top-level volumes
    expect($parsed)->toHaveKey('volumes');
    expect($parsed['volumes'])->toHaveKey('mysmb');
    expect($parsed['volumes']['mysmb'])->toHaveKey('driver_opts');
    expect($parsed['volumes']['mysmb']['driver_opts'])->toHaveKey('type', 'cifs');

    // Verify service has both volumes
    expect($parsed['services']['webserver'])->toHaveKey('volumes');
    expect($parsed['services']['webserver']['volumes'])->toHaveCount(2);

    // Verify SMB volume reference exists in service volumes
    $serviceVolumes = $parsed['services']['webserver']['volumes'];
    $hasSmbVolume = false;
    foreach ($serviceVolumes as $volume) {
        if (is_string($volume) && str_contains($volume, 'mysmb')) {
            $hasSmbVolume = true;
            break;
        }
    }
    expect($hasSmbVolume)->toBeTrue('Service should reference the SMB volume');
});

it('verifies mixed SMB and regular volumes are both preserved', function () {
    $composeWithMixedVolumes = <<<'YAML'
services:
  app:
    image: nginx:latest
    volumes:
      - smb_volume:/smb
      - regular_volume:/data
      - ./local:/app

volumes:
  smb_volume:
    driver: local
    driver_opts:
      type: cifs
      o: username=user,password=pass
      device: //server/share
  regular_volume:
    driver: local
YAML;

    $parsed = Yaml::parse($composeWithMixedVolumes);

    // Verify both volume types exist in top-level volumes
    expect($parsed['volumes'])->toHaveKey('smb_volume');
    expect($parsed['volumes'])->toHaveKey('regular_volume');

    // Verify SMB volume has driver_opts
    expect($parsed['volumes']['smb_volume'])->toHaveKey('driver_opts');
    expect($parsed['volumes']['smb_volume']['driver_opts']['type'])->toBe('cifs');

    // Verify regular volume exists
    expect($parsed['volumes']['regular_volume'])->toHaveKey('driver');

    // Verify service has all three volumes
    expect($parsed['services']['app']['volumes'])->toHaveCount(3);
});

it('verifies NFS volumes are also preserved', function () {
    $composeWithNfs = <<<'YAML'
services:
  app:
    image: nginx:latest
    volumes:
      - nfs_volume:/nfs

volumes:
  nfs_volume:
    driver: local
    driver_opts:
      type: nfs
      o: addr=192.168.1.1
      device: ":/exports"
YAML;

    $parsed = Yaml::parse($composeWithNfs);

    // Verify NFS volume is in top-level volumes
    expect($parsed['volumes'])->toHaveKey('nfs_volume');
    expect($parsed['volumes']['nfs_volume']['driver_opts']['type'])->toBe('nfs');

    // Verify service references the NFS volume
    expect($parsed['services']['app']['volumes'])->toHaveCount(1);
    $volume = $parsed['services']['app']['volumes'][0];
    expect($volume)->toBeString();
    expect($volume)->toContain('nfs_volume');
});

it('verifies any custom driver_opts.type is preserved', function () {
    // Test with tmpfs as another example of a custom driver type
    $composeWithTmpfs = <<<'YAML'
services:
  app:
    image: nginx:latest
    volumes:
      - tmpfs_volume:/tmp

volumes:
  tmpfs_volume:
    driver: local
    driver_opts:
      type: tmpfs
      device: tmpfs
      o: size=100m,uid=1000
YAML;

    $parsed = Yaml::parse($composeWithTmpfs);

    // Verify tmpfs volume is in top-level volumes
    expect($parsed['volumes'])->toHaveKey('tmpfs_volume');
    expect($parsed['volumes']['tmpfs_volume']['driver_opts']['type'])->toBe('tmpfs');

    // Verify service references the tmpfs volume
    expect($parsed['services']['app']['volumes'])->toHaveCount(1);
    $volume = $parsed['services']['app']['volumes'][0];
    expect($volume)->toBeString();
    expect($volume)->toContain('tmpfs_volume');
});
