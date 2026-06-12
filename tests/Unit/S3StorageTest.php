<?php

use App\Models\S3Storage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

test('S3Storage model has correct cast definitions', function () {
    $s3Storage = new S3Storage;
    $casts = $s3Storage->getCasts();

    expect($casts['is_usable'])->toBe('boolean');
    expect($casts['key'])->toBe('encrypted');
    expect($casts['secret'])->toBe('encrypted');
});

test('S3Storage isUsable method returns is_usable attribute value', function () {
    $s3Storage = new S3Storage;

    // Set the attribute directly to avoid encryption
    $s3Storage->setRawAttributes(['is_usable' => true]);
    expect($s3Storage->isUsable())->toBeTrue();

    $s3Storage->setRawAttributes(['is_usable' => false]);
    expect($s3Storage->isUsable())->toBeFalse();

    $s3Storage->setRawAttributes(['is_usable' => null]);
    expect($s3Storage->isUsable())->toBeNull();
});

test('S3Storage awsUrl method constructs correct URL format', function () {
    $s3Storage = new S3Storage;

    // Set attributes without triggering encryption
    $s3Storage->setRawAttributes([
        'endpoint' => 'https://s3.amazonaws.com',
        'bucket' => 'test-bucket',
    ]);

    expect($s3Storage->awsUrl())->toBe('https://s3.amazonaws.com/test-bucket');

    // Test with custom endpoint
    $s3Storage->setRawAttributes([
        'endpoint' => 'https://minio.example.com:9000',
        'bucket' => 'backups',
    ]);

    expect($s3Storage->awsUrl())->toBe('https://minio.example.com:9000/backups');
});

test('S3Storage model fillable attributes are configured correctly', function () {
    $s3Storage = new S3Storage;

    expect($s3Storage->getFillable())->toBe([
        'name',
        'description',
        'region',
        'key',
        'secret',
        'bucket',
        'endpoint',
        'is_usable',
        'unusable_email_sent',
    ]);
});

test('S3Storage connection validation uses short s3 client timeouts', function () {
    $disk = Mockery::mock();
    $disk->expects('files')->once()->andReturn([]);

    Storage::expects('build')
        ->once()
        ->with(Mockery::on(function (array $config) {
            expect($config['http']['connect_timeout'])->toBe(15);
            expect($config['http']['timeout'])->toBe(15);

            return true;
        }))
        ->andReturn($disk);

    $s3Storage = new S3Storage;
    $s3Storage->setRawAttributes([
        'name' => 'Test S3',
        'region' => 'us-east-1',
        'key' => null,
        'secret' => null,
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.amazonaws.com',
    ]);

    $s3Storage->testConnection();

    expect($s3Storage->is_usable)->toBeTrue();
});

test('S3Storage connection validation returns friendly timeout error', function () {
    $disk = Mockery::mock();
    $disk->expects('files')
        ->once()
        ->andThrow(new RuntimeException('cURL error 28: Operation timed out after 15000 milliseconds'));

    Storage::expects('build')->once()->andReturn($disk);

    $s3Storage = new S3Storage;
    $s3Storage->setRawAttributes([
        'name' => 'Test S3',
        'region' => 'us-east-1',
        'key' => null,
        'secret' => null,
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.amazonaws.com',
        'unusable_email_sent' => true,
    ]);

    expect(fn () => $s3Storage->testConnection())
        ->toThrow(RuntimeException::class, 'Could not connect to the S3 endpoint within 15 seconds.');

    expect($s3Storage->is_usable)->toBeFalse();
});
