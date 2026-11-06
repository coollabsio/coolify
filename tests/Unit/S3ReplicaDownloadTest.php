<?php

use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Support\StreamsDownloads;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\PhpseclibV3\ConnectionProvider;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\UnableToRetrieveMetadata;
use phpseclib3\Net\SFTP;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A backup execution of `/backup.sql` with one replica per storage, in this order.
 *
 * @param  array<int, S3Storage>  $storages
 */
function replicaDownloadTestExecution(array $storages): ScheduledDatabaseBackupExecution
{
    $relation = Mockery::mock(HasMany::class);
    foreach (['with', 'where', 'whereHas', 'orderBy'] as $method) {
        $relation->shouldReceive($method)->andReturnSelf();
    }
    $relation->shouldReceive('get')->andReturn(collect($storages)->map(fn (S3Storage $storage, int $index): object => (object) ['s3' => $storage, 's3_storage_id' => $index + 1]));
    $execution = Mockery::mock(ScheduledDatabaseBackupExecution::class)->makePartial();
    $execution->filename = '/backup.sql';
    $execution->shouldReceive('s3Replicas')->andReturn($relation);

    return $execution;
}

function replicaDownloadTestStorage(FilesystemAdapter|Throwable $disk): S3Storage
{
    $storage = Mockery::mock(S3Storage::class)->makePartial();
    $storage->shouldReceive('isS3')->andReturnFalse();
    $disk instanceof Throwable
        ? $storage->shouldReceive('filesystem')->andThrow($disk)
        : $storage->shouldReceive('filesystem')->andReturn($disk);

    return $storage;
}

/**
 * A disk whose adapter writes the contents straight to the destination; `$downloaded` turns true on the download.
 */
function replicaDownloadTestStreamingDisk(string $contents, bool &$downloaded = false): FilesystemAdapter
{
    $adapter = Mockery::mock(FlysystemAdapter::class, StreamsDownloads::class);
    $adapter->shouldReceive('download')->with('backup.sql', Mockery::type('resource'), strlen($contents))
        ->andReturnUsing(function (string $path, $destination) use ($contents, &$downloaded): void {
            $downloaded = true;
            fwrite($destination, $contents);
        });
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('size')->with('backup.sql')->andReturn(strlen($contents));
    $disk->shouldReceive('getAdapter')->andReturn($adapter);

    return $disk;
}

function replicaDownloadTestMissingFileDisk(): FilesystemAdapter
{
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('size')->andThrow(UnableToRetrieveMetadata::fileSize('backup.sql', 'The file does not exist.'));

    return $disk;
}

function replicaDownloadTestBody(Response $response): string
{
    ob_start();
    try {
        $response->sendContent();

        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

it('streams a replica with its size and reads it only when the download is sent', function () {
    $downloaded = false;
    $execution = replicaDownloadTestExecution([replicaDownloadTestStorage(replicaDownloadTestStreamingDisk('backup contents', $downloaded))]);

    $response = $execution->s3Download(1, 1);

    expect($downloaded)->toBeFalse()
        ->and($response->headers->get('Content-Length'))->toBe('15')
        ->and(replicaDownloadTestBody($response))->toBe('backup contents')
        ->and($downloaded)->toBeTrue();
});

it('falls back to the next replica when a copy cannot be read', function (S3Storage $failingStorage) {
    $execution = replicaDownloadTestExecution([$failingStorage, replicaDownloadTestStorage(replicaDownloadTestStreamingDisk('second copy'))]);

    $response = $execution->s3Download(1, 1);

    expect($response->headers->get('Content-Length'))->toBe('11')
        ->and(replicaDownloadTestBody($response))->toBe('second copy');
})->with([
    'missing file' => fn () => replicaDownloadTestStorage(replicaDownloadTestMissingFileDisk()),
    'unreachable storage' => fn () => replicaDownloadTestStorage(new RuntimeException('Could not resolve the host.')),
]);

it('returns no download when no replica has the file', function () {
    expect(replicaDownloadTestExecution([replicaDownloadTestStorage(replicaDownloadTestMissingFileDisk())])->s3Download(1, 1))->toBeNull();
});

it('skips a storage whose adapter cannot stream a download', function () {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('size')->with('backup.sql')->andReturn(15);
    $disk->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
    $disk->shouldReceive('readStream')->never();

    $execution = replicaDownloadTestExecution([replicaDownloadTestStorage($disk), replicaDownloadTestStorage(replicaDownloadTestStreamingDisk('second copy'))]);

    expect(replicaDownloadTestBody($execution->s3Download(1, 1)))->toBe('second copy');
});

it('streams an SFTP replica to the response without a temporary copy, at most the file size', function () {
    // phpseclib defines these in the SFTP constructor, which the mock skips.
    defined('NET_SFTP_TYPE_REGULAR') || define('NET_SFTP_TYPE_REGULAR', 1);
    defined('NET_SFTP_TYPE_DIRECTORY') || define('NET_SFTP_TYPE_DIRECTORY', 2);
    $connection = Mockery::mock(SFTP::class);
    $connection->allows('getServerPublicHostKey')->andReturn(false);
    $connection->allows('stat')->with('/backups/backup.sql')->andReturn(['size' => 15, 'mode' => 0100644, 'type' => NET_SFTP_TYPE_REGULAR]);
    // Like phpseclib: a resource destination must support fstat(), which php://output does not; a callable gets each chunk.
    $connection->expects('get')->with('/backups/backup.sql', Mockery::any(), 0, 15)
        ->andReturnUsing(function (string $path, $destination): bool {
            if (is_resource($destination)) {
                $offset = fstat($destination)['size'];
                fwrite($destination, substr('backup contents', $offset));
            } else {
                $destination('backup contents');
            }

            return true;
        });
    app()->bind(SftpConnectionProvider::class, fn () => new class($connection) implements ConnectionProvider
    {
        public function __construct(private SFTP $connection) {}

        public function provideConnection(): SFTP
        {
            return $this->connection;
        }

        public function disconnect(): void {}
    });
    $storage = new S3Storage;
    $storage->forceFill(['type' => 'sftp', 'endpoint' => 'sftp://1.1.1.1/backups', 'key' => 'backup', 'secret' => 'password', 'sftp_host_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILhw/KsYTF9TQOwQX1EAM3gI5oxDS9Daz5nNuCixSRXT']);

    $response = replicaDownloadTestExecution([$storage])->s3Download(1, 1);

    expect($response->headers->get('Content-Length'))->toBe('15')
        ->and(replicaDownloadTestBody($response))->toBe('backup contents');
});
