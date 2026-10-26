<?php

use App\Services\ServerTransfer\ServerTransferBundle;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

test('wrap adds schema version export id and timestamp', function () {
    $bundle = ServerTransferBundle::wrap(['server' => ['uuid' => 'abc']]);

    expect($bundle['schema_version'])->toBe(ServerTransferBundle::SCHEMA_VERSION)
        ->and($bundle['export_id'])->toBeString()->not->toBeEmpty()
        ->and($bundle['exported_at'])->toBeString()
        ->and($bundle['server']['uuid'])->toBe('abc');
});

test('validate rejects missing required fields', function () {
    $result = ServerTransferBundle::validate([]);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty();
});

test('validate accepts a minimal valid bundle', function () {
    $bundle = ServerTransferBundle::wrap([
        'private_key' => ['private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----"],
        'server' => [
            'uuid' => 'srv-1',
            'name' => 'web',
            'ip' => '10.0.0.1',
            'port' => 22,
            'user' => 'root',
        ],
        'destinations' => [],
        'projects' => [],
    ]);

    $result = ServerTransferBundle::validate($bundle);

    expect($result['valid'])->toBeTrue()
        ->and($result['warnings'])->not->toBeEmpty(); // empty destinations warning
});

test('assertValid throws validation exception', function () {
    ServerTransferBundle::assertValid(['schema_version' => 99]);
})->throws(ValidationException::class);

function bundleTestMinimalBundle(): array
{
    return ServerTransferBundle::wrap([
        'private_key' => ['private_key' => 'x'],
        'server' => ['uuid' => 's', 'name' => 'n', 'ip' => '1.1.1.1', 'port' => 22, 'user' => 'root'],
        'destinations' => [],
        'projects' => [],
    ]);
}

test('validate rejects a bundle from a newer or unknown Coolify version', function (?string $version, string $error) {
    $bundle = bundleTestMinimalBundle();
    $bundle['coolify_version'] = $version;

    $result = ServerTransferBundle::validate($bundle);

    expect($result['valid'])->toBeFalse()
        ->and(implode(' ', $result['errors']))->toContain($error);
})->with([
    'newer' => ['99.0.0', 'Upgrade this instance'],
    'missing' => [null, 'Missing coolify_version'],
]);

test('validate accepts a bundle from an older Coolify version', function () {
    $bundle = bundleTestMinimalBundle();
    $bundle['coolify_version'] = '4.0.0';

    expect(ServerTransferBundle::validate($bundle)['valid'])->toBeTrue();
});

test('validate accepts Coolify ids and legacy RFC 4122 uuids', function () {
    $bundle = bundleTestMinimalBundle();
    $bundle['server']['uuid'] = new_public_id();
    $bundle['private_key']['uuid'] = (string) Str::uuid();
    $bundle['volume_backups'] = [['uuid' => 'abc_DEF-123', 's3_storage_uuids' => [(string) Str::uuid()], 'frequency' => 'daily']];

    expect(ServerTransferBundle::validate($bundle)['errors'])->toBe([]);
});

test('validate rejects an unsafe id in an id list', function () {
    $bundle = bundleTestMinimalBundle();
    $bundle['volume_backups'] = [['s3_storage_uuids' => ['ok', '../../x']]];

    expect(ServerTransferBundle::validate($bundle)['errors'])->toContain('volume_backups.0.s3_storage_uuids.1 is not a valid id. An id may only contain letters, digits, hyphens, and underscores (at most 64 characters).');
});
