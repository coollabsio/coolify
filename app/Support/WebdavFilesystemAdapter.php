<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
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
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * A small WebDAV adapter for backup storages. All paths are relative to the base URL. The Guzzle client comes from
 * the caller, so it has the DNS pinning and the timeouts of the storage.
 */
class WebdavFilesystemAdapter implements FilesystemAdapter
{
    private const METADATA_TIMEOUT_SECONDS = 15;

    private const PROPFIND_BODY = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:getcontentlength/><d:getlastmodified/><d:getcontenttype/></d:prop></d:propfind>';

    private string $basePath;

    public function __construct(private Client $client, string $baseUrl)
    {
        $this->basePath = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/').'/';
    }

    public function fileExists(string $path): bool
    {
        try {
            $entry = $this->stat($path);
        } catch (Throwable $exception) {
            throw UnableToCheckExistence::forLocation($path, $exception);
        }

        return $entry instanceof FileAttributes;
    }

    public function directoryExists(string $path): bool
    {
        try {
            $entry = $this->stat($path);
        } catch (Throwable $exception) {
            throw UnableToCheckExistence::forLocation($path, $exception);
        }

        return $entry instanceof DirectoryAttributes;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->writeStream($path, Utils::streamFor($contents), $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $this->createParentDirectories($path);
            $this->expectSuccess($this->client->request('PUT', $this->uri($path), ['body' => $contents]));
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

    public function readStream(string $path)
    {
        try {
            $response = $this->expectSuccess($this->client->request('GET', $this->uri($path), ['stream' => true]));
            $stream = $response->getBody()->detach();
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }

        if (! is_resource($stream)) {
            throw UnableToReadFile::fromLocation($path, 'The response has no body.');
        }

        return $stream;
    }

    public function delete(string $path): void
    {
        try {
            $response = $this->client->request('DELETE', $this->uri($path), ['timeout' => self::METADATA_TIMEOUT_SECONDS]);
            if ($response->getStatusCode() !== 404) {
                $this->expectSuccess($response);
            }
        } catch (Throwable $exception) {
            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            $response = $this->client->request('DELETE', $this->uri($path, directory: true), ['timeout' => self::METADATA_TIMEOUT_SECONDS]);
            if ($response->getStatusCode() !== 404) {
                $this->expectSuccess($response);
            }
        } catch (Throwable $exception) {
            throw UnableToDeleteDirectory::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $current = '';
            foreach (array_filter(explode('/', $path), fn (string $segment): bool => $segment !== '') as $segment) {
                $current .= $segment.'/';
                $response = $this->client->request('MKCOL', $this->uri($current, directory: true), ['timeout' => self::METADATA_TIMEOUT_SECONDS]);
                // 405: the directory exists already.
                if ($response->getStatusCode() !== 405) {
                    $this->expectSuccess($response);
                }
            }
        } catch (Throwable $exception) {
            throw UnableToCreateDirectory::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'WebDAV storages do not support visibility.');
    }

    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, 'WebDAV storages do not support visibility.');
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->fileMetadata($path, 'mimeType', fn (FileAttributes $file): bool => $file->mimeType() !== null);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->fileMetadata($path, 'lastModified', fn (FileAttributes $file): bool => $file->lastModified() !== null);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->fileMetadata($path, 'fileSize', fn (FileAttributes $file): bool => $file->fileSize() !== null);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        try {
            $entries = $this->propfind($path, 1, directory: true);
        } catch (Throwable $exception) {
            throw UnableToListContents::atLocation($path, $deep, $exception);
        }

        if ($entries === []) {
            throw UnableToListContents::atLocation($path, $deep, new RuntimeException('The directory does not exist on the WebDAV server.'));
        }

        $self = trim($path, '/');
        foreach ($entries as $entry) {
            if (trim($entry->path(), '/') === $self) {
                continue;
            }

            yield $entry;

            if ($deep && $entry instanceof DirectoryAttributes) {
                yield from $this->listContents($entry->path(), true);
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->transfer('MOVE', $source, $destination);
        } catch (Throwable $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->transfer('COPY', $source, $destination);
        } catch (Throwable $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
    }

    private function transfer(string $method, string $source, string $destination): void
    {
        $this->createParentDirectories($destination);
        $this->expectSuccess($this->client->request($method, $this->uri($source), [
            'timeout' => self::METADATA_TIMEOUT_SECONDS,
            'headers' => ['Destination' => $this->absoluteUri($destination), 'Overwrite' => 'T'],
        ]));
    }

    /**
     * @param  callable(FileAttributes): bool  $hasValue
     */
    private function fileMetadata(string $path, string $type, callable $hasValue): FileAttributes
    {
        try {
            $entry = $this->stat($path);
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::create($path, $type, $exception->getMessage(), $exception);
        }

        if (! $entry instanceof FileAttributes || ! $hasValue($entry)) {
            throw UnableToRetrieveMetadata::create($path, $type, 'The file does not exist or the server does not return this value.');
        }

        return $entry;
    }

    /**
     * The file or directory at the path, or null when it does not exist.
     */
    private function stat(string $path): FileAttributes|DirectoryAttributes|null
    {
        return $this->propfind($path, 0)[0] ?? null;
    }

    /**
     * @return array<int, FileAttributes|DirectoryAttributes>
     */
    private function propfind(string $path, int $depth, bool $directory = false): array
    {
        $response = $this->client->request('PROPFIND', $this->uri($path, $directory), [
            'timeout' => self::METADATA_TIMEOUT_SECONDS,
            'headers' => ['Depth' => (string) $depth, 'Content-Type' => 'application/xml; charset=utf-8'],
            'body' => self::PROPFIND_BODY,
        ]);
        if ($response->getStatusCode() === 404) {
            return [];
        }
        $this->expectSuccess($response);

        $document = new DOMDocument;
        if (! @$document->loadXML((string) $response->getBody(), LIBXML_NONET)) {
            throw new RuntimeException('The WebDAV server returned an invalid PROPFIND response.');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', 'DAV:');

        $entries = [];
        foreach ($xpath->query('//d:multistatus/d:response') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $href = rawurldecode((string) parse_url(trim((string) $xpath->evaluate('string(d:href)', $node)), PHP_URL_PATH));
            $relative = str_starts_with($href, $this->basePath) ? substr($href, strlen($this->basePath)) : ltrim($href, '/');
            $relative = trim($relative, '/');
            $prop = 'd:propstat[contains(d:status, " 200 ")]/d:prop/';

            if ($xpath->query($prop.'d:resourcetype/d:collection', $node)->length > 0) {
                $entries[] = new DirectoryAttributes($relative);

                continue;
            }

            $size = $xpath->evaluate('string('.$prop.'d:getcontentlength)', $node);
            $modified = $xpath->evaluate('string('.$prop.'d:getlastmodified)', $node);
            $mimeType = $xpath->evaluate('string('.$prop.'d:getcontenttype)', $node);
            $entries[] = new FileAttributes(
                $relative,
                is_numeric($size) ? (int) $size : null,
                null,
                $modified !== '' && strtotime($modified) !== false ? strtotime($modified) : null,
                $mimeType !== '' ? $mimeType : null,
            );
        }

        return $entries;
    }

    private function createParentDirectories(string $path): void
    {
        $parent = dirname(trim($path, '/'));
        if ($parent !== '.' && $parent !== '') {
            $this->createDirectory($parent, new Config);
        }
    }

    private function expectSuccess(ResponseInterface $response): ResponseInterface
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("The WebDAV server answered with HTTP {$status} {$response->getReasonPhrase()}.");
        }

        return $response;
    }

    private function uri(string $path, bool $directory = false): string
    {
        $encoded = implode('/', array_map('rawurlencode', array_filter(explode('/', $path), fn (string $segment): bool => $segment !== '')));

        return $encoded === '' ? '' : $encoded.($directory ? '/' : '');
    }

    private function absoluteUri(string $path): string
    {
        return (string) Utils::uriFor($this->client->getConfig('base_uri'))->withPath($this->basePath.$this->uri($path));
    }
}
