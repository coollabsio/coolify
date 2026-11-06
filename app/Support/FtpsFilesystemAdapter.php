<?php

namespace App\Support;

use InvalidArgumentException;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use RuntimeException;
use Throwable;

/**
 * A small FTPS (explicit TLS) adapter for backup storages, on the curl extension. The PHP ftp extension cannot reuse
 * the TLS session on data connections, which most FTPS servers require. curl verifies the server certificate, connects
 * to the pinned IP, and ignores the address in the passive mode reply.
 */
class FtpsFilesystemAdapter implements DeletesEmptyDirectories, FilesystemAdapter, StreamsDownloads
{
    private const CONNECTION_TIMEOUT_SECONDS = 15;

    /**
     * The limit of a request that transfers no file (metadata, listing, delete, move).
     */
    protected const REQUEST_TIMEOUT_SECONDS = 30;

    /**
     * A file transfer fails when it moves no data for this long.
     */
    protected const STALL_SECONDS = 60;

    /**
     * @param  string  $root  The base directory: absolute with a leading slash, else relative to the login directory.
     * @param  string|null  $pinnedIp  The IP to connect to when the host is a name.
     */
    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        private string $root = '',
        private ?string $pinnedIp = null,
    ) {}

    public function fileExists(string $path): bool
    {
        try {
            $this->request($path, [CURLOPT_NOBODY => true]);

            return true;
        } catch (FtpsRequestFailed $exception) {
            if ($exception->isNotFound()) {
                return false;
            }

            throw UnableToCheckExistence::forLocation($path, $exception);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            $this->request($path, [CURLOPT_NOBODY => true], directory: true);

            return true;
        } catch (FtpsRequestFailed $exception) {
            if ($exception->isNotFound()) {
                return false;
            }

            throw UnableToCheckExistence::forLocation($path, $exception);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);

        try {
            $this->writeStream($path, $stream, $config);
        } finally {
            fclose($stream);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $this->request($path, [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $contents,
                CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
            ], transfer: true);
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);

        try {
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Downloads the file to a temporary stream first, as the SFTP adapter does.
     */
    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');

        try {
            $this->download($path, $stream);
        } catch (Throwable $exception) {
            fclose($stream);

            throw $exception;
        }
        rewind($stream);

        return $stream;
    }

    /**
     * @param  resource  $destination
     */
    public function download(string $path, $destination, ?int $maxBytes = null): void
    {
        $received = 0;

        try {
            $this->request($path, [
                // Writing less than the chunk makes curl stop the transfer.
                CURLOPT_WRITEFUNCTION => function ($handle, string $data) use ($destination, $maxBytes, &$received): int {
                    $received += strlen($data);

                    return $maxBytes !== null && $received > $maxBytes ? 0 : (int) fwrite($destination, $data);
                },
            ], transfer: true);
        } catch (Throwable $exception) {
            $message = $maxBytes !== null && $received > $maxBytes ? 'The server sent more data than the file size.' : $exception->getMessage();

            throw UnableToReadFile::fromLocation($path, $message, $exception);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->command($path, 'DELE');
        } catch (Throwable $exception) {
            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            $this->command($path, 'RMD');
        } catch (Throwable $exception) {
            throw UnableToDeleteDirectory::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * RMD, which the server refuses for a directory that is not empty.
     */
    public function deleteEmptyDirectory(string $path): bool
    {
        try {
            $this->command($path, 'RMD');

            return true;
        } catch (FtpsRequestFailed) {
            return false;
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->request($path, [CURLOPT_NOBODY => true, CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR], directory: true);
        } catch (Throwable $exception) {
            throw UnableToCreateDirectory::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'FTPS storages do not support visibility.');
    }

    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, 'FTPS storages do not support visibility.');
    }

    public function mimeType(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::mimeType($path, 'FTPS storages do not return MIME types.');
    }

    public function lastModified(string $path): FileAttributes
    {
        $modified = $this->fileInfo($path, 'lastModified')[CURLINFO_FILETIME_T];
        if ($modified < 0) {
            throw UnableToRetrieveMetadata::lastModified($path, 'The server does not return the modification time.');
        }

        return new FileAttributes($path, lastModified: $modified);
    }

    public function fileSize(string $path): FileAttributes
    {
        $size = $this->fileInfo($path, 'fileSize')[CURLINFO_CONTENT_LENGTH_DOWNLOAD_T];
        if ($size < 0) {
            throw UnableToRetrieveMetadata::fileSize($path, 'The server does not return the file size.');
        }

        return new FileAttributes($path, fileSize: $size);
    }

    /**
     * Lists the names in a directory (NLST). Backups only need the names, and NLST works on every FTP server.
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $stream = fopen('php://temp', 'w+b');

        try {
            $this->request($path, [CURLOPT_DIRLISTONLY => true, CURLOPT_FILE => $stream], directory: true);
            rewind($stream);
            $names = preg_split('/\r?\n/', (string) stream_get_contents($stream), flags: PREG_SPLIT_NO_EMPTY);
        } catch (Throwable $exception) {
            throw UnableToListContents::atLocation($path, $deep, $exception);
        } finally {
            fclose($stream);
        }

        $prefix = trim($path, '/');
        foreach ($names as $name) {
            $name = basename($name);
            if ($name !== '.' && $name !== '..') {
                yield new FileAttributes(ltrim($prefix.'/'.$name, '/'));
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->request('', [CURLOPT_NOBODY => true, CURLOPT_QUOTE => [
                'RNFR '.$this->fullPath($source),
                'RNTO '.$this->fullPath($destination),
            ]], directory: true);
        } catch (Throwable $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        throw UnableToCopyFile::fromLocationTo($source, $destination, new RuntimeException('FTPS storages do not support copies.'));
    }

    /**
     * @return array<int, int>
     */
    private function fileInfo(string $path, string $type): array
    {
        try {
            return $this->request($path, [CURLOPT_NOBODY => true, CURLOPT_FILETIME => true]);
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::create($path, $type, $exception->getMessage(), $exception);
        }
    }

    /**
     * Sends a command that takes a path (DELE, RMD) after the login.
     */
    private function command(string $path, string $command): void
    {
        $this->request('', [CURLOPT_NOBODY => true, CURLOPT_QUOTE => [$command.' '.$this->fullPath($path)]], directory: true);
    }

    /**
     * @param  array<int, mixed>  $options
     * @param  bool  $transfer  Whether the request uploads or downloads a file: it has no total time limit then.
     * @return array<int, int> The size and the modification time of the response.
     */
    private function request(string $path, array $options, bool $directory = false, bool $transfer = false): array
    {
        $handle = curl_init($this->url($path, $directory));
        $host = $this->bracketed($this->host);
        // array_replace() keeps the integer keys of the curl options, which the spread operator renumbers.
        curl_setopt_array($handle, array_replace([
            // Not CURLOPT_USERPWD: it splits "user:password" at the first colon, so a username with a colon fails.
            CURLOPT_USERNAME => $this->username,
            CURLOPT_PASSWORD => $this->password,
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_PROTOCOLS_STR => 'ftp',
            CURLOPT_CONNECTTIMEOUT => self::CONNECTION_TIMEOUT_SECONDS,
            CURLOPT_FTP_SKIP_PASV_IP => true,
            CURLOPT_RETURNTRANSFER => true,
        ], $transfer
            ? [CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => static::STALL_SECONDS, CURLOPT_FTP_RESPONSE_TIMEOUT => static::STALL_SECONDS]
            : [CURLOPT_TIMEOUT => static::REQUEST_TIMEOUT_SECONDS], $this->pinnedIp !== null ? [CURLOPT_RESOLVE => [$host.':'.$this->port.':'.$this->bracketed($this->pinnedIp)]] : [], $options));

        $result = curl_exec($handle);
        $error = curl_errno($handle);
        $info = [
            CURLINFO_CONTENT_LENGTH_DOWNLOAD_T => (int) curl_getinfo($handle, CURLINFO_CONTENT_LENGTH_DOWNLOAD_T),
            CURLINFO_FILETIME_T => (int) curl_getinfo($handle, CURLINFO_FILETIME_T),
        ];
        if ($result === false || $error !== 0) {
            throw new FtpsRequestFailed($error, curl_error($handle));
        }

        return $info;
    }

    private function url(string $path, bool $directory): string
    {
        $host = $this->bracketed($this->host);
        $fullPath = $this->fullPath($path);
        // curl reads a URL path as relative to the login directory; %2F at the start makes it absolute.
        $encoded = implode('/', array_map('rawurlencode', array_filter(explode('/', $fullPath), fn (string $segment): bool => $segment !== '')));
        $urlPath = str_starts_with($fullPath, '/') ? '%2F'.$encoded : $encoded;

        return 'ftp://'.$host.':'.$this->port.'/'.$urlPath.($directory && $urlPath !== '' ? '/' : '');
    }

    private function bracketed(string $host): string
    {
        $host = trim($host, '[]');

        return str_contains($host, ':') ? '['.$host.']' : $host;
    }

    /**
     * Control characters are refused, as a line break would end the FTP command and start another one.
     */
    private function fullPath(string $path): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $path.$this->root)) {
            throw new InvalidArgumentException('The path contains control characters.');
        }
        $path = trim($path, '/');
        $root = rtrim($this->root, '/');

        if ($path === '') {
            return $root === '' && str_starts_with($this->root, '/') ? '/' : $root;
        }

        return $root === '' ? $path : $root.'/'.$path;
    }
}
