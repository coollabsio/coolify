<?php

use App\Models\S3Storage;
use App\Support\DeletesEmptyDirectories;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A storage whose files are in a local directory. Its directory removal refuses a directory with content, as SFTP and
 * FTPS servers do.
 */
function fileRetentionTestStorage(string $root): S3Storage
{
    $adapter = new class($root) extends LocalFilesystemAdapter implements DeletesEmptyDirectories
    {
        public function __construct(private string $base)
        {
            parent::__construct($base);
        }

        public function deleteEmptyDirectory(string $path): bool
        {
            return @rmdir($this->base.'/'.$path);
        }
    };
    $storage = Mockery::mock(S3Storage::class)->makePartial();
    $storage->forceFill(['type' => 'sftp']);
    $storage->shouldReceive('filesystem')->andReturn(new FilesystemAdapter(new Filesystem($adapter), $adapter));

    return $storage;
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/retention-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/data/coolify/backups/db-a', 0777, true);
    mkdir($this->root.'/data/coolify/backups/db-b', 0777, true);
    file_put_contents($this->root.'/data/coolify/backups/db-a/old.dmp', 'a');
    file_put_contents($this->root.'/data/coolify/backups/db-b/keep.dmp', 'b');
    file_put_contents($this->root.'/data/coolify/backups/db-b/old.dmp', 'b');
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
});

it('removes the directories that retention left empty, up to the storage base path', function () {
    deleteBackupsS3(['/data/coolify/backups/db-a/old.dmp', '/data/coolify/backups/db-b/old.dmp'], fileRetentionTestStorage($this->root));

    expect(is_dir($this->root.'/data/coolify/backups/db-a'))->toBeFalse()
        ->and(is_file($this->root.'/data/coolify/backups/db-b/keep.dmp'))->toBeTrue()
        ->and(is_dir($this->root.'/data/coolify/backups'))->toBeTrue();
});

it('removes every empty parent directory, but never the base path', function () {
    exec('rm -rf '.escapeshellarg($this->root.'/data/coolify/backups/db-b'));

    deleteBackupsS3('/data/coolify/backups/db-a/old.dmp', fileRetentionTestStorage($this->root));

    expect(is_dir($this->root.'/data'))->toBeFalse()
        ->and(is_dir($this->root))->toBeTrue();
});
