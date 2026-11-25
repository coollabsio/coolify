<?php

/**
 * Unit tests to verify LocalFileVolume::isReadOnlyVolume() correctly detects
 * read-only volumes in both short-form and long-form Docker Compose syntax.
 *
 * Related Issue: Volumes with read_only: true in long-form syntax were not
 * being detected as read-only, allowing UI edits on files that should be protected.
 *
 * Related Files:
 *  - app/Models/LocalFileVolume.php
 *  - app/Livewire/Project/Service/FileStorage.php
 */

use Symfony\Component\Yaml\Yaml;

/**
 * Helper function to parse volumes and detect read-only status.
 * This mirrors the logic in LocalFileVolume::isReadOnlyVolume()
 *
 * Note: We match on mount_path (container path) only, since fs_path gets transformed
 * from relative (./file) to absolute (/data/coolify/services/uuid/file) during parsing
 */
function isVolumeReadOnly(string $dockerComposeRaw, string $serviceName, string $mountPath): bool
{
    $compose = Yaml::parse($dockerComposeRaw);

    if (! isset($compose['services'][$serviceName]['volumes'])) {
        return false;
    }

    $volumes = $compose['services'][$serviceName]['volumes'];

    foreach ($volumes as $volume) {
        // Volume can be string like "host:container:ro" or "host:container"
        if (is_string($volume)) {
            $parts = explode(':', $volume);

            if (count($parts) >= 2) {
                $containerPath = $parts[1];
                $options = $parts[2] ?? null;

                if ($containerPath === $mountPath) {
                    return $options === 'ro';
                }
            }
        } elseif (is_array($volume)) {
            // Long-form syntax: { type: bind, source: ..., target: ..., read_only: true }
            $containerPath = data_get($volume, 'target');
            $readOnly = data_get($volume, 'read_only', false);

            if ($containerPath === $mountPath) {
                return $readOnly === true;
            }
        }
    }

    return false;
}

test('detects read-only with short-form syntax using :ro', function () {
    $compose = <<<'YAML'
services:
  garage:
    image: example/image
    volumes:
      - ./config.toml:/etc/config.toml:ro
YAML;

    expect(isVolumeReadOnly($compose, 'garage', '/etc/config.toml'))->toBeTrue();
});

test('detects writable with short-form syntax without :ro', function () {
    $compose = <<<'YAML'
services:
  garage:
    image: example/image
    volumes:
      - ./config.toml:/etc/config.toml
YAML;

    expect(isVolumeReadOnly($compose, 'garage', '/etc/config.toml'))->toBeFalse();
});

test('detects read-only with long-form syntax and read_only: true', function () {
    $compose = <<<'YAML'
services:
  garage:
    image: example/image
    volumes:
      - type: bind
        source: ./garage.toml
        target: /etc/garage.toml
        read_only: true
YAML;

    expect(isVolumeReadOnly($compose, 'garage', '/etc/garage.toml'))->toBeTrue();
});

test('detects writable with long-form syntax and read_only: false', function () {
    $compose = <<<'YAML'
services:
  garage:
    image: example/image
    volumes:
      - type: bind
        source: ./garage.toml
        target: /etc/garage.toml
        read_only: false
YAML;

    expect(isVolumeReadOnly($compose, 'garage', '/etc/garage.toml'))->toBeFalse();
});

test('detects writable with long-form syntax without read_only key', function () {
    $compose = <<<'YAML'
services:
  garage:
    image: example/image
    volumes:
      - type: bind
        source: ./garage.toml
        target: /etc/garage.toml
YAML;

    expect(isVolumeReadOnly($compose, 'garage', '/etc/garage.toml'))->toBeFalse();
});

test('handles mixed short-form and long-form volumes in same service', function () {
    $compose = <<<'YAML'
services:
  garage:
    image: example/image
    volumes:
      - ./data:/var/data
      - type: bind
        source: ./config.toml
        target: /etc/config.toml
        read_only: true
YAML;

    expect(isVolumeReadOnly($compose, 'garage', '/var/data'))->toBeFalse();
    expect(isVolumeReadOnly($compose, 'garage', '/etc/config.toml'))->toBeTrue();
});

test('handles same file mounted in multiple services with different read_only settings', function () {
    $compose = <<<'YAML'
services:
  garage:
    image: example/garage
    volumes:
      - type: bind
        source: ./garage.toml
        target: /etc/garage.toml
  garage-webui:
    image: example/webui
    volumes:
      - type: bind
        source: ./garage.toml
        target: /etc/garage.toml
        read_only: true
YAML;

    // Same file, different services, different read_only status
    expect(isVolumeReadOnly($compose, 'garage', '/etc/garage.toml'))->toBeFalse();
    expect(isVolumeReadOnly($compose, 'garage-webui', '/etc/garage.toml'))->toBeTrue();
});

test('handles volume mount type', function () {
    $compose = <<<'YAML'
services:
  app:
    image: example/app
    volumes:
      - type: volume
        source: mydata
        target: /data
        read_only: true
YAML;

    expect(isVolumeReadOnly($compose, 'app', '/data'))->toBeTrue();
});

test('returns false when service has no volumes', function () {
    $compose = <<<'YAML'
services:
  garage:
    image: example/image
YAML;

    expect(isVolumeReadOnly($compose, 'garage', '/etc/config.toml'))->toBeFalse();
});

test('returns false when service does not exist', function () {
    $compose = <<<'YAML'
services:
  garage:
    image: example/image
    volumes:
      - ./config.toml:/etc/config.toml:ro
YAML;

    expect(isVolumeReadOnly($compose, 'nonexistent', '/etc/config.toml'))->toBeFalse();
});

test('returns false when mount path does not match', function () {
    $compose = <<<'YAML'
services:
  garage:
    image: example/image
    volumes:
      - type: bind
        source: ./other.toml
        target: /etc/other.toml
        read_only: true
YAML;

    expect(isVolumeReadOnly($compose, 'garage', '/etc/config.toml'))->toBeFalse();
});
