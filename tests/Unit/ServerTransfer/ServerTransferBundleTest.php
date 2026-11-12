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

function bundleTestWithDatabasePorts(array $attributes): array
{
    $bundle = bundleTestMinimalBundle();
    $bundle['projects'] = [['environments' => [['databases' => [['attributes' => $attributes]]]]]];

    return $bundle;
}

test('validate accepts the port values that older databases have', function (array $attributes) {
    expect(ServerTransferBundle::validate(bundleTestWithDatabasePorts($attributes))['errors'])->toBe([]);
})->with([
    'no ports' => [['ports_mappings' => null, 'public_port' => null]],
    'empty mappings' => [['ports_mappings' => '']],
    'mapping list' => [['ports_mappings' => '5432:5432,8080:80']],
    'spaces and a trailing comma' => [['ports_mappings' => '5432:5432, 8080:80,']],
    'container port only' => [['ports_mappings' => '5432']],
    'ip, range and protocol' => [['ports_mappings' => '127.0.0.1:8000-8010:8000-8010/udp,[::1]::80']],
    'public port below 1024' => [['public_port' => 543]],
    'public port as digit string' => [['public_port' => '5432']],
]);

test('validate rejects garbage database port values', function (array $attributes, string $field) {
    $errors = ServerTransferBundle::validate(bundleTestWithDatabasePorts($attributes))['errors'];

    expect(implode(' ', $errors))->toContain("projects.0.environments.0.databases.0.{$field}");
})->with([
    'shell in mappings' => [['ports_mappings' => '5432:5432; rm -rf /'], 'ports_mappings'],
    'yaml in mappings' => [['ports_mappings' => "5432:5432\nprivileged: true"], 'ports_mappings'],
    'letters in mappings' => [['ports_mappings' => 'abc:def'], 'ports_mappings'],
    'mappings as array' => [['ports_mappings' => ['5432:5432']], 'ports_mappings'],
    'public port zero' => [['public_port' => 0], 'public_port'],
    'public port too high' => [['public_port' => 70000], 'public_port'],
    'public port text' => [['public_port' => '5432; id'], 'public_port'],
]);
