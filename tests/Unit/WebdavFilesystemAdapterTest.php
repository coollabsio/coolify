<?php

use App\Support\WebdavFilesystemAdapter;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use League\Flysystem\Config;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;

/**
 * Starts the WebDAV router fixture on a free loopback port.
 *
 * @return array{0: resource, 1: int}
 */
function startWebdavTestServer(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:'.$port, dirname(__DIR__).'/Fixtures/storage/webdav-router.php'],
        [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
        null,
        ['PHP_CLI_SERVER_WORKERS' => '4'],
    );
    $probe = curl_init('http://127.0.0.1:'.$port.'/');
    curl_setopt_array($probe, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
    for ($attempt = 0; $attempt < 50 && curl_exec($probe) === false; $attempt++) {
        usleep(100_000);
    }

    return [$process, $port];
}

/**
 * An adapter for the local server, as S3Storage::webdavFilesystem() builds it, with a 1-second stall limit. A host name
 * reaches the server only through the DNS pin of the client.
 */
function localWebdavTestAdapter(int $port, string $host = '127.0.0.1'): WebdavFilesystemAdapter
{
    $baseUrl = 'http://'.$host.':'.$port.'/';
    $client = new Client([
        'allow_redirects' => false,
        ...(filter_var($host, FILTER_VALIDATE_IP) ? [] : ['curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':127.0.0.1']]]),
        'base_uri' => $baseUrl,
        'http_errors' => false,
        'connect_timeout' => 5,
    ]);

    return new class($client, $baseUrl) extends WebdavFilesystemAdapter
    {
        protected const STALL_SECONDS = 1;
    };
}

/**
 * @param  array<int, Response>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function mockedWebdavTestAdapter(string $baseUrl, array $responses, array &$history = []): WebdavFilesystemAdapter
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new WebdavFilesystemAdapter(new Client(['handler' => $stack, 'base_uri' => $baseUrl, 'http_errors' => false]), $baseUrl);
}

describe('with a local WebDAV server', function () {
    beforeEach(function () {
        [$this->server, $this->port] = startWebdavTestServer();
    });

    afterEach(function () {
        proc_terminate($this->server, SIGKILL);
        proc_close($this->server);
    });

    it('downloads through the pinned IP of the host', function () {
        $adapter = localWebdavTestAdapter($this->port, 'webdav-pin.invalid');

        $stream = $adapter->readStream('backup.sql');
        $destination = fopen('php://memory', 'w+b');
        $adapter->download('backup.sql', $destination, 1000);
        rewind($destination);

        expect(stream_get_contents($stream))->toBe('backup contents')
            ->and(stream_get_contents($destination))->toBe('backup contents');
    });

    it('fails a download that stalls', function () {
        $adapter = localWebdavTestAdapter($this->port);
        $started = microtime(true);

        expect(fn () => $adapter->read('stall.sql'))->toThrow(UnableToReadFile::class)
            ->and(microtime(true) - $started)->toBeLessThan(10);
    });

    it('fails an upload that gets no answer', function () {
        $adapter = localWebdavTestAdapter($this->port);
        $started = microtime(true);

        expect(fn () => $adapter->write('stall.sql', 'contents', new Config))->toThrow(UnableToWriteFile::class)
            ->and(microtime(true) - $started)->toBeLessThan(15);
    });

    it('stops a download at the expected size', function () {
        $adapter = localWebdavTestAdapter($this->port);
        $destination = fopen('php://memory', 'w+b');

        expect(fn () => $adapter->download('large.sql', $destination, 10))->toThrow(UnableToReadFile::class);
        rewind($destination);
        expect(strlen(stream_get_contents($destination)))->toBeLessThanOrEqual(10);
    });

    it('writes nothing of an error response', function () {
        $adapter = localWebdavTestAdapter($this->port);
        $destination = fopen('php://memory', 'w+b');

        expect(fn () => $adapter->download('missing.sql', $destination, 1000))->toThrow(UnableToReadFile::class);
        rewind($destination);
        expect(stream_get_contents($destination))->toBe('');
    });
});

it('lists a base URL with percent-encoded characters', function (string $basePath) {
    $entry = fn (string $href, string $type): string => '<d:response><d:href>'.$href.'</d:href><d:propstat><d:prop><d:resourcetype>'.$type.'</d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
    $body = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">'
        .$entry($basePath.'backups/', '<d:collection/>')
        .$entry($basePath.'backups/db.sql', '')
        .'</d:multistatus>';
    $adapter = mockedWebdavTestAdapter('https://dav.example.com'.$basePath, [new Response(207, [], $body)]);

    $paths = array_map(fn ($entry): string => $entry->path(), iterator_to_array($adapter->listContents('backups', false), false));

    expect($paths)->toBe(['backups/db.sql']);
})->with([
    '/remote.php/dav/files/john%40example.com/',
    '/dav/My%20Backups/',
]);

it('rejects control characters in paths before a request', function () {
    $history = [];
    $adapter = mockedWebdavTestAdapter('https://dav.example.com/dav/', [new Response(204)], $history);

    expect(fn () => $adapter->delete("backup.sql\r\nX-Injected: 1"))->toThrow(UnableToDeleteFile::class)
        ->and($history)->toBeEmpty();
});
