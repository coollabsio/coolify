<?php

use Tests\TestCase;

uses(TestCase::class);

/**
 * `docker compose up` gives a recreated container the old mount at each path that the image
 * declares with VOLUME, also a volume that the user deleted in Coolify (#6875).
 */
function inspectedComposeContainer(string $service, array $imageVolumes, array $mounts): string
{
    return json_encode([
        'name' => "/{$service}-abc123",
        'service' => $service,
        'imageVolumes' => $imageVolumes === [] ? null : array_fill_keys($imageVolumes, new stdClass),
        'mounts' => $mounts,
    ]);
}

function namedVolumeMount(string $name, string $destination): array
{
    return ['Type' => 'volume', 'Name' => $name, 'Source' => "/var/lib/docker/volumes/{$name}/_data", 'Destination' => $destination];
}

it('finds a container whose deleted volume Compose would keep', function () {
    $projectMounts = inspectedComposeContainer('db', ['/var/lib/postgresql/data'], [
        namedVolumeMount('abc123_db-data', '/var/lib/postgresql/data'),
    ]);

    expect(composeContainersWithRemovedVolumes($projectMounts, ['db' => ['image' => 'postgres:17']], 'abc123', ['abc123_other']))
        ->toBe(['db-abc123' => 'abc123_db-data']);
});

it('keeps a volume that is only removed from the Compose file, because its storage row still exists', function () {
    $projectMounts = inspectedComposeContainer('db', ['/var/lib/postgresql/data'], [
        namedVolumeMount('abc123_db-data', '/var/lib/postgresql/data'),
    ]);

    expect(composeContainersWithRemovedVolumes($projectMounts, ['db' => ['image' => 'postgres:17']], 'abc123', ['abc123_db-data']))->toBe([]);
});

it('keeps external volumes and bind mounts, because Coolify did not make them', function (array $mount) {
    $projectMounts = inspectedComposeContainer('app', ['/data/'], [$mount]);

    expect(composeContainersWithRemovedVolumes($projectMounts, ['app' => ['image' => 'app', 'volumes' => ['/other:/other']]], 'abc123', []))->toBe([]);
})->with([
    'external volume' => [namedVolumeMount('shared-data', '/data')],
    'bind mount' => [['Type' => 'bind', 'Source' => '/data/coolify/services/abc123/data', 'Destination' => '/data']],
]);

it('keeps a container when the Compose file still mounts something at the path', function (mixed $volume) {
    $projectMounts = inspectedComposeContainer('db', ['/var/lib/postgresql/data'], [
        namedVolumeMount('abc123_db-data', '/var/lib/postgresql/data'),
    ]);

    expect(composeContainersWithRemovedVolumes($projectMounts, ['db' => ['volumes' => [$volume]]], 'abc123', []))->toBe([]);
})->with([
    'short syntax' => 'abc123_db-data:/var/lib/postgresql/data',
    'short syntax with mode' => 'other-data:/var/lib/postgresql/data/:rw',
    'source with a default value' => '${DATA_PATH:-/srv/db}:/var/lib/postgresql/data',
    'anonymous volume' => '/var/lib/postgresql/data',
    'long syntax' => [['type' => 'volume', 'source' => 'other', 'target' => '/var/lib/postgresql/data']],
]);

it('keeps a container whose anonymous volume Compose keeps on purpose', function () {
    $projectMounts = inspectedComposeContainer('db', ['/var/lib/postgresql/data'], [
        namedVolumeMount(str_repeat('a1', 32), '/var/lib/postgresql/data'),
    ]);

    expect(composeContainersWithRemovedVolumes($projectMounts, ['db' => ['image' => 'postgres:17']], 'abc123', []))->toBe([]);
});

it('keeps a container when its image declares no volume at the removed path', function () {
    $projectMounts = inspectedComposeContainer('app', [], [
        namedVolumeMount('abc123_app-data', '/app/data'),
        ['Type' => 'bind', 'Source' => '/data/coolify/secrets/s1', 'Destination' => '/run/secrets/s1'],
        ['Type' => 'tmpfs', 'Destination' => '/tmp'],
    ]);

    expect(composeContainersWithRemovedVolumes($projectMounts, ['app' => ['image' => 'app']], 'abc123', []))->toBe([]);
});

it('keeps containers of services that up does not start, and paths it cannot read', function () {
    $projectMounts = implode("\n", [
        inspectedComposeContainer('old-service', ['/data'], [namedVolumeMount('abc123_old', '/data')]),
        inspectedComposeContainer('web', ['/data'], [namedVolumeMount('abc123_web', '/data')]),
        'Error: No such object',
    ]);

    expect(composeContainersWithRemovedVolumes($projectMounts, ['web' => ['volumes' => ['web-data:${DATA_TARGET}']]], 'abc123', []))->toBe([]);
});
