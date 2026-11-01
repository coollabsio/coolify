<?php

namespace App\Support;

use League\Flysystem\PathPrefixer;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\UnableToReadFile;
use Throwable;

/**
 * The SFTP adapter of backup storages. It checks the saved host key on every connection, and downloads write straight
 * to the destination stream instead of a temporary copy of the whole file.
 */
class SftpFilesystemAdapter extends SftpAdapter implements DeletesEmptyDirectories, StreamsDownloads
{
    private PathPrefixer $pathPrefixer;

    public function __construct(private PinnedSftpConnectionProvider $connections, string $root)
    {
        parent::__construct($connections, $root);
        $this->pathPrefixer = new PathPrefixer($root);
    }

    /**
     * Writes at most `$maxBytes` bytes, so a file that grew since its size was read cannot overrun the response.
     * phpseclib calls fstat() on a resource destination, which php://output does not support, so it gets a callable.
     */
    public function download(string $path, $destination, int $maxBytes): void
    {
        $write = function (string $chunk) use ($destination): void {
            fwrite($destination, $chunk);
        };

        try {
            $downloaded = $this->connections->provideConnection()->get($this->pathPrefixer->prefixPath($path), $write, 0, $maxBytes);
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
        if ($downloaded === false) {
            throw UnableToReadFile::fromLocation($path);
        }
    }

    /**
     * RMDIR, which the server refuses for a directory that is not empty. deleteDirectory() deletes the content first.
     */
    public function deleteEmptyDirectory(string $path): bool
    {
        return $this->connections->provideConnection()->rmdir($this->pathPrefixer->prefixPath($path));
    }

    /**
     * The host key that the server presented, as "<key type> <base64>".
     */
    public function presentedHostKey(): ?string
    {
        return $this->connections->presentedHostKey();
    }
}
