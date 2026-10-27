<?php

use App\Models\S3Storage;
use Illuminate\Filesystem\FilesystemAdapter;
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
        'team_id',
        'name',
        'description',
        'region',
        'key',
        'secret',
        'bucket',
        'endpoint',
        'use_path_style_endpoint',
        'is_usable',
        'unusable_email_sent',
    ]);
});

test('S3Storage connection validation uses short s3 client timeouts', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->expects('files')->once()->andReturn([]);

    Storage::expects('build')
        ->once()
        ->with(Mockery::on(function (array $config) {
            expect($config['http']['connect_timeout'])->toBe(15);
            expect($config['http']['timeout'])->toBe(15);
            expect($config['http']['allow_redirects'])->toBeFalse();

            return true;
        }))
        ->andReturn($disk);

    $s3Storage = new S3Storage;
    $s3Storage->setRawAttributes([
        'name' => 'Test S3',
        'region' => 'us-east-1',
        'key' => null,
        'secret' => null,
        'bucket' => 'Test-Bucket',
        'endpoint' => 'https://s3.amazonaws.com',
    ]);

    $s3Storage->testConnection();

    expect($s3Storage->is_usable)->toBeTrue();
});

test('S3Storage uses path-style URLs unless the storage turns them off', function (?bool $setting, bool $expected, string $mcOption) {
    $disk = Mockery::mock(FilesystemAdapter::class);
    Storage::expects('build')
        ->once()
        ->with(Mockery::on(fn (array $config) => $config['use_path_style_endpoint'] === $expected))
        ->andReturn($disk);

    $s3Storage = new S3Storage;
    $s3Storage->setRawAttributes([
        'region' => 'oss-ap-southeast-1',
        'bucket' => 'backups',
        'endpoint' => 'https://s3.oss-ap-southeast-1.aliyuncs.com',
        'use_path_style_endpoint' => $setting,
    ]);

    expect($s3Storage->filesystem())->toBe($disk)
        ->and($s3Storage->minioClientPathOption())->toBe($mcOption);
})->with([
    'not saved yet' => [null, true, ''],
    'path-style' => [true, true, ''],
    'virtual-hosted-style' => [false, false, ' --path off'],
]);

test('S3Storage detects the URL style from the endpoint host', function (?string $endpoint, bool $usesPathStyle) {
    expect(S3Storage::detectPathStyleEndpoint($endpoint))->toBe($usesPathStyle);
})->with([
    'Alibaba Cloud OSS' => ['https://s3.oss-ap-southeast-1-internal.aliyuncs.com', false],
    'Tencent Cloud COS' => ['https://cos.ap-guangzhou.myqcloud.com', false],
    'Volcengine TOS' => ['https://tos-s3-cn-beijing.volces.com', false],
    'BytePlus TOS' => ['https://tos-s3-ap-southeast-1.bytepluses.com', false],
    'AWS' => ['https://s3.us-east-1.amazonaws.com', true],
    'look-alike host' => ['https://notaliyuncs.com', true],
    'MinIO by IP' => ['http://192.168.1.50:9000', true],
    'empty' => [null, true],
]);

test('S3Storage connection validation switches to virtual-hosted-style URLs when the provider rejects path-style URLs', function () {
    $pathStyleDisk = Mockery::mock(FilesystemAdapter::class);
    $pathStyleDisk->expects('files')->once()->andThrow(new RuntimeException(
        'Unable to list contents',
        previous: new RuntimeException('AWS HTTP error: SecondLevelDomainForbidden (client): Please use virtual hosted style to access.'),
    ));
    $virtualHostedDisk = Mockery::mock(FilesystemAdapter::class);
    $virtualHostedDisk->expects('files')->once()->andReturn([]);
    Storage::expects('build')->with(Mockery::on(fn (array $config) => $config['use_path_style_endpoint'] === true))->once()->andReturn($pathStyleDisk);
    Storage::expects('build')->with(Mockery::on(fn (array $config) => $config['use_path_style_endpoint'] === false))->once()->andReturn($virtualHostedDisk);

    $s3Storage = new S3Storage;
    $s3Storage->setRawAttributes(['region' => 'us-east-1', 'bucket' => 'backups', 'endpoint' => 'https://s3.amazonaws.com']);

    $s3Storage->testConnection();

    expect($s3Storage->is_usable)->toBeTrue()
        ->and($s3Storage->usesPathStyleEndpoint())->toBeFalse();
});

test('S3Storage connection validation keeps path-style URLs when the virtual-hosted-style test also fails', function () {
    $pathStyleError = new RuntimeException('AWS HTTP error: PathStyleDomainForbidden (client): use a virtual-styled domain');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->expects('files')->twice()->andThrow($pathStyleError, new RuntimeException('AccessDenied'));
    Storage::expects('build')->twice()->andReturn($disk);

    $s3Storage = new S3Storage;
    $s3Storage->setRawAttributes(['region' => 'us-east-1', 'bucket' => 'backups', 'endpoint' => 'https://s3.amazonaws.com']);

    expect(fn () => $s3Storage->testConnection())->toThrow(RuntimeException::class, 'PathStyleDomainForbidden')
        ->and($s3Storage->usesPathStyleEndpoint())->toBeTrue()
        ->and($s3Storage->is_usable)->toBeFalse();
});

test('S3Storage connection validation does not retry other errors', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->expects('files')->once()->andThrow(new RuntimeException('AccessDenied'));
    Storage::expects('build')->once()->andReturn($disk);

    $s3Storage = new S3Storage;
    $s3Storage->setRawAttributes(['region' => 'us-east-1', 'bucket' => 'backups', 'endpoint' => 'https://s3.amazonaws.com']);

    expect(fn () => $s3Storage->testConnection())->toThrow(RuntimeException::class, 'AccessDenied')
        ->and($s3Storage->usesPathStyleEndpoint())->toBeTrue();
});

test('S3Storage connection validation returns friendly timeout error', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
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

test('S3Storage testConnection rejects invalid bucket before building client', function (string $bucket) {
    Storage::shouldReceive('build')->never();

    $s3Storage = new S3Storage;
    $s3Storage->setRawAttributes([
        'name' => 'Test S3',
        'region' => 'us-east-1',
        'key' => 'AKIAEXAMPLE',
        'secret' => 'secret',
        'bucket' => $bucket,
        'endpoint' => 'https://s3.amazonaws.com',
    ]);

    expect(fn () => $s3Storage->testConnection())
        ->toThrow(RuntimeException::class, 'S3 bucket name is not allowed');
})->with([
    'semicolon injection' => ['lab; id; #'],
    'command substitution' => ['lab$(id)'],
    'backticks' => ['lab`id`'],
    'newline' => ["lab\nid"],
    'underscore' => ['lab_bucket'],
]);
