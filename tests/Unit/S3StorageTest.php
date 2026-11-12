<?php

use App\Models\S3Storage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
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
        'type',
        'name',
        'description',
        'region',
        'key',
        'secret',
        'bucket',
        'endpoint',
        'use_path_style_endpoint',
        'private_key_id',
        'skip_tls_verification',
        'is_usable',
        'unusable_email_sent',
    ])->not->toContain('sftp_host_key');
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
        ->and($s3Storage->minioClientAliasOptions())->toBe($mcOption);
})->with([
    'not saved yet' => [null, true, ' --api S3v4'],
    'path-style' => [true, true, ' --api S3v4'],
    'virtual-hosted-style' => [false, false, ' --api S3v4 --path off'],
]);

test('S3Storage checks the TLS certificate unless the storage skips the check', function (?bool $setting, bool $verify, string $mcOptions) {
    $disk = Mockery::mock(FilesystemAdapter::class);
    Storage::expects('build')
        ->once()
        ->with(Mockery::on(fn (array $config) => $config['http']['verify'] === $verify))
        ->andReturn($disk);

    $s3Storage = new S3Storage;
    $s3Storage->setRawAttributes([
        'region' => 'us-east-1',
        'bucket' => 'backups',
        'endpoint' => 'https://93.184.216.34:9000',
        'skip_tls_verification' => $setting,
    ]);

    expect($s3Storage->filesystem())->toBe($disk)
        ->and($s3Storage->httpClientOptions()['verify'])->toBe($verify)
        ->and($s3Storage->minioClientEnvOptions())->toBe($mcOptions);
})->with([
    'not saved yet' => [null, true, "-e 'MC_REGION=us-east-1'"],
    'checks the certificate' => [false, true, "-e 'MC_REGION=us-east-1'"],
    'skips the check' => [true, false, "-e 'MC_REGION=us-east-1' -e MC_INSECURE=true"],
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

test('S3Storage retries a failed MinIO client upload and waits longer before each attempt', function () {
    Sleep::fake();
    $attempts = 0;

    $result = S3Storage::retryMinioClientUpload(function () use (&$attempts) {
        if (++$attempts < 3) {
            throw new RuntimeException('dial tcp: connect: network is unreachable', 1);
        }

        return 'uploaded';
    });

    expect($result)->toBe('uploaded')->and($attempts)->toBe(3);
    Sleep::assertSequence([Sleep::for(10)->seconds(), Sleep::for(30)->seconds()]);
});

test('S3Storage stops retrying a MinIO client upload after three attempts', function () {
    Sleep::fake();
    $attempts = 0;

    $upload = function () use (&$attempts) {
        $attempts++;

        throw new RuntimeException('Access Denied.', 1);
    };

    expect(fn () => S3Storage::retryMinioClientUpload($upload))->toThrow(RuntimeException::class, 'Access Denied.');
    expect($attempts)->toBe(3);
});

test('S3Storage does not retry a MinIO client upload that timed out', function () {
    Sleep::fake();
    $attempts = 0;

    $upload = function () use (&$attempts) {
        $attempts++;

        throw new RuntimeException('The command on the server timed out after 3600 seconds.', 124);
    };

    expect(fn () => S3Storage::retryMinioClientUpload($upload))->toThrow(RuntimeException::class, 'timed out');
    expect($attempts)->toBe(1);
    Sleep::assertNeverSlept();
});

test('S3Storage sizes MinIO client upload parts for at most 1000 parts', function (int $fileSize, int $partSize) {
    expect(S3Storage::minioClientPartSizeOption($fileSize))->toBe('-e MC_UPLOAD_MULTIPART_SIZE='.$partSize)
        ->and((int) ceil($fileSize / $partSize))->toBeLessThanOrEqual(1000);
})->with([
    'empty file keeps the 16 MiB default' => [0, 16 * 1024 * 1024],
    'small file keeps the 16 MiB default' => [128, 16 * 1024 * 1024],
    '1000 parts of 16 MiB keep the default' => [1000 * 16 * 1024 * 1024, 16 * 1024 * 1024],
    'one byte more needs 17 MiB parts' => [1000 * 16 * 1024 * 1024 + 1, 17 * 1024 * 1024],
    '19 GiB dump' => [19 * 1024 * 1024 * 1024, 20 * 1024 * 1024],
    '1 TiB dump' => [1024 * 1024 * 1024 * 1024, 1049 * 1024 * 1024],
]);

test('S3Storage uses curl transfers only for endpoints with a path, which the MinIO client rejects', function (string $endpoint, bool $expected) {
    $s3Storage = new S3Storage;
    $s3Storage->setRawAttributes(['endpoint' => $endpoint]);

    expect($s3Storage->usesCurlTransfer())->toBe($expected);
})->with([
    'Supabase Storage' => ['https://abcd.storage.supabase.co/storage/v1/s3', true],
    'path with trailing slash' => ['http://minio:9000/s3/', true],
    'host only' => ['https://s3.amazonaws.com', false],
    'host with trailing slash' => ['https://s3.amazonaws.com/', false],
    'host with port' => ['http://coolify-minio:9000', false],
]);

test('storages of the other types never use the S3 curl transfer, also with a path in the endpoint', function (string $type, string $endpoint) {
    $storage = new S3Storage;
    $storage->setRawAttributes(['type' => $type, 'endpoint' => $endpoint]);

    expect($storage->usesCurlTransfer())->toBeFalse();
})->with([
    'WebDAV' => ['webdav', 'https://cloud.example.com/remote.php/dav/files/user/backups'],
    'SFTP' => ['sftp', 'sftp://backup.example.com:22/backups'],
    'FTPS' => ['ftps', 'ftps://backup.example.com:21/backups'],
]);
