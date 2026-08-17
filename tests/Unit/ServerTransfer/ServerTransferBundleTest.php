<?php

use App\Services\ServerTransfer\ServerTransferBundle;
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

test('passphrase encrypt decrypt round trip', function () {
    $original = ServerTransferBundle::wrap([
        'private_key' => ['private_key' => 'secret-key-material'],
        'server' => [
            'uuid' => 'srv-1',
            'name' => 'web',
            'ip' => '10.0.0.1',
            'port' => 22,
            'user' => 'root',
        ],
        'destinations' => [['uuid' => 'd1', 'name' => 'coolify', 'network' => 'coolify', 'type' => 'standalone']],
        'projects' => [],
    ]);

    $encrypted = ServerTransferBundle::encryptWithPassphrase($original, 'correct horse battery staple');

    expect($encrypted['encrypted'])->toBeTrue()
        ->and($encrypted['payload'])->toBeString();

    $restored = ServerTransferBundle::decryptWithPassphrase($encrypted, 'correct horse battery staple');

    expect($restored['export_id'])->toBe($original['export_id'])
        ->and($restored['server']['uuid'])->toBe('srv-1')
        ->and($restored['private_key']['private_key'])->toBe('secret-key-material');
});

test('wrong passphrase fails decrypt', function () {
    $original = ServerTransferBundle::wrap([
        'private_key' => ['private_key' => 'x'],
        'server' => ['uuid' => 's', 'name' => 'n', 'ip' => '1.1.1.1', 'port' => 22, 'user' => 'root'],
        'destinations' => [],
        'projects' => [],
    ]);

    $encrypted = ServerTransferBundle::encryptWithPassphrase($original, 'good-pass');

    ServerTransferBundle::decryptWithPassphrase($encrypted, 'bad-pass');
})->throws(RuntimeException::class);

test('app key seal unseal round trip', function () {
    $original = ['hello' => 'world', 'n' => 1];
    $sealed = ServerTransferBundle::sealWithAppKey($original);
    $restored = ServerTransferBundle::unsealWithAppKey($sealed);

    expect($restored)->toBe($original);
});
