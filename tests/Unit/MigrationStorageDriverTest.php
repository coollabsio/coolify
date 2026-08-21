<?php

use App\Enums\MigrationStorageDriver;
use App\Models\Server;
use App\Services\Migration\Storage\AzureBlobDriver;
use App\Services\Migration\Storage\GcsDriver;
use App\Services\Migration\Storage\LocalSshDriver;
use App\Services\Migration\Storage\MigrationStorageFactory;
use App\Services\Migration\Storage\S3CompatibleDriver;

test('local ssh driver stores and retrieves archives on disk', function () {
    $base = sys_get_temp_dir().'/migrations-test-'.uniqid();
    $driver = new LocalSshDriver(['base_path' => $base]);
    $source = $base.'/source.txt';
    mkdir($base, 0777, true);
    file_put_contents($source, 'volume-bytes');

    $server = new Server;
    $pointer = $driver->put($server, $source, 'run/volume.tar.gz');

    expect($pointer['driver'])->toBe('local-ssh')
        ->and($pointer['size_bytes'])->toBe(12)
        ->and($driver->exists($server, 'run/volume.tar.gz'))->toBeTrue();

    $downloaded = $base.'/downloaded.txt';
    $driver->get($server, 'run/volume.tar.gz', $downloaded);
    expect(file_get_contents($downloaded))->toBe('volume-bytes');

    $driver->delete($server, $pointer['key']);
    expect($driver->exists($server, $pointer['key']))->toBeFalse();

    @unlink($downloaded);
    @unlink($source);
    @rmdir($base.'/run');
    @rmdir($base);
});

test('factory maps aws and coolify cloud aliases to the s3 driver', function () {
    $factory = new MigrationStorageFactory;
    $driver = $factory->make(MigrationStorageDriver::fromAlias('aws'), [
        'endpoint' => 'https://s3.amazonaws.com',
        'bucket' => 'migrations',
        'key' => 'key',
        'secret' => 'secret',
    ], 1);

    expect($driver)->toBeInstanceOf(S3CompatibleDriver::class);
});

test('factory maps azure and gcs drivers', function () {
    $factory = new MigrationStorageFactory;

    expect($factory->make(MigrationStorageDriver::fromAlias('azure'), [
        'account' => 'account',
        'container' => 'migrations',
        'sas' => 'token',
    ], 1))->toBeInstanceOf(AzureBlobDriver::class)
        ->and($factory->make(MigrationStorageDriver::fromAlias('gcs'), [
            'bucket' => 'migrations',
        ], 1))->toBeInstanceOf(GcsDriver::class);
});
