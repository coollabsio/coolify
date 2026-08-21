<?php

use App\Services\Migration\Manifest;

test('builds a versioned manifest and orders databases before services and applications', function () {
    $manifest = Manifest::make(
        storage: ['driver' => 's3'],
        resources: [
            ['type' => 'application', 'source_uuid' => 'app-1', 'volumes' => [['archive' => ['size_bytes' => 10]]]],
            ['type' => 'standalone-postgresql', 'source_uuid' => 'db-1', 'volumes' => [['archive' => ['size_bytes' => 20]]]],
            ['type' => 'service', 'source_uuid' => 'svc-1', 'dump' => ['archive' => ['size_bytes' => 5]]],
        ],
        skipData: false,
    );

    expect($manifest->version)->toBe(Manifest::VERSION)
        ->and($manifest->resourceCount())->toBe(3)
        ->and($manifest->totalArchiveBytes())->toBe(35);

    $ordered = $manifest->resourcesInImportOrder();
    expect($ordered[0]['type'])->toBe('standalone-postgresql')
        ->and($ordered[1]['type'])->toBe('service')
        ->and($ordered[2]['type'])->toBe('application');
});

test('round-trips a manifest through array serialization', function () {
    $manifest = Manifest::make(
        storage: ['driver' => 'local-ssh'],
        resources: [['type' => 'application', 'source_uuid' => 'app-1']],
        skipData: true,
    );

    $restored = Manifest::fromArray([
        'version' => $manifest->version,
        'exported_at' => $manifest->exported_at,
        'source_version' => $manifest->source_version,
        'skip_data' => $manifest->skip_data,
        'storage' => $manifest->storage,
        'resources' => $manifest->resources,
    ]);

    expect($restored->skip_data)->toBeTrue()
        ->and($restored->storage['driver'])->toBe('local-ssh')
        ->and($restored->resources[0]['source_uuid'])->toBe('app-1');
});

test('replaces linked database uuids in environment values', function () {
    $value = replace_database_uuids_in_value(
        'postgres://postgres:secret@old-db-uuid:5432/app',
        ['old-db-uuid' => 'new-db-uuid'],
    );

    expect($value)->toBe('postgres://postgres:secret@new-db-uuid:5432/app');
});
