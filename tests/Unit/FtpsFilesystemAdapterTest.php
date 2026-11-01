<?php

use App\Support\FtpsFilesystemAdapter;
use League\Flysystem\Config;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use Symfony\Component\Process\Process;

/**
 * A self-signed certificate for `localhost`, with its key, in one PEM file.
 */
function fakeFtpsTestCertificate(): string
{
    $path = sys_get_temp_dir().'/coolify-fake-ftps-'.getmypid().'.pem';
    if (is_file($path)) {
        return $path;
    }

    $config = tempnam(sys_get_temp_dir(), 'openssl');
    file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[san]\nsubjectAltName = DNS:localhost\n");
    $options = ['config' => $config, 'x509_extensions' => 'san', 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'localhost'], $key, $options), null, $key, 1, $options);
    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($key, $keyPem);
    file_put_contents($path, $certificatePem.$keyPem);
    unlink($config);

    return $path;
}

/**
 * Runs an adapter call in a child PHP that trusts the test certificate (curl.cainfo cannot be set at runtime). The
 * adapter connects to `localhost` through the pinned IP and has 1-second limits.
 *
 * @return array{error: string|null, seconds: float, bytes: int}
 */
function runFakeFtpsTestCall(string $scenario, string $call, int $maxBytes = 0, string $username = 'user', ?string $commandLog = null): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
    $server = new Process([PHP_BINARY, dirname(__DIR__).'/Fixtures/storage/fake-ftps-server.php', (string) $port, fakeFtpsTestCertificate(), $scenario, ...($commandLog === null ? [] : [$commandLog])]);
    $server->start();
    usleep(300_000);

    $code = <<<'PHP'
        require $argv[1].'/vendor/autoload.php';
        $adapter = new class('localhost', (int) $argv[2], $argv[5], 'secret', '', '127.0.0.1') extends App\Support\FtpsFilesystemAdapter
        {
            protected const REQUEST_TIMEOUT_SECONDS = 1;

            protected const STALL_SECONDS = 1;
        };
        $destination = fopen('php://memory', 'w+b');
        $started = microtime(true);
        try {
            match ($argv[3]) {
                'fileExists' => $adapter->fileExists('backup.sql'),
                'read' => fwrite($destination, $adapter->read('backup.sql')),
                'download' => $adapter->download('backup.sql', $destination, (int) $argv[4]),
            };
            $error = null;
        } catch (Throwable $exception) {
            $error = $exception::class.': '.$exception->getMessage();
        }
        $seconds = microtime(true) - $started;
        rewind($destination);
        echo json_encode(['error' => $error, 'seconds' => $seconds, 'bytes' => strlen(stream_get_contents($destination))]);
        PHP;

    try {
        $client = new Process([PHP_BINARY, '-d', 'curl.cainfo='.fakeFtpsTestCertificate(), '-r', $code, dirname(__DIR__, 2), (string) $port, $call, (string) $maxBytes, $username]);
        $client->setTimeout(180)->mustRun();

        return json_decode($client->getOutput(), true);
    } finally {
        $server->stop(0);
    }
}

it('formats FTPS URL hosts exactly once', function (string $host, string $expected) {
    $adapter = new FtpsFilesystemAdapter($host, 21, 'user', 'password');

    $url = (new ReflectionMethod($adapter, 'url'))->invoke($adapter, 'backup.sql', false);

    expect($url)->toBe('ftp://'.$expected.':21/backup.sql');
})->with([
    ['[2001:db8::1]', '[2001:db8::1]'],
    ['2001:db8::1', '[2001:db8::1]'],
    ['192.0.2.1', '192.0.2.1'],
    ['backup.example.com', 'backup.example.com'],
]);

it('downloads a file through the pinned IP', function () {
    expect(runFakeFtpsTestCall('serve', 'download', 100))->toMatchArray(['error' => null, 'bytes' => 100]);
});

it('logs in with a username that contains a colon', function () {
    $commandLog = tempnam(sys_get_temp_dir(), 'fake-ftps-log');

    try {
        expect(runFakeFtpsTestCall('serve', 'download', 100, 'backup:user', $commandLog))->toMatchArray(['error' => null, 'bytes' => 100])
            ->and(array_values(preg_grep('/^(USER|PASS) /', file($commandLog, FILE_IGNORE_NEW_LINES))))->toBe(['USER backup:user', 'PASS secret']);
    } finally {
        unlink($commandLog);
    }
});

it('stops a download at the expected size', function () {
    $result = runFakeFtpsTestCall('serve', 'download', 10);

    expect($result['error'])->toStartWith('League\Flysystem\UnableToReadFile')
        ->and($result['bytes'])->toBeLessThanOrEqual(10);
});

it('fails a metadata request that stalls', function () {
    $result = runFakeFtpsTestCall('stall-size', 'fileExists');

    expect($result['error'])->toStartWith('League\Flysystem\UnableToCheckExistence')
        ->and($result['seconds'])->toBeLessThan(10);
});

it('fails a download that stalls', function () {
    $result = runFakeFtpsTestCall('stall-data', 'read');

    expect($result['error'])->toStartWith('League\Flysystem\UnableToReadFile')
        ->and($result['seconds'])->toBeLessThan(10);
});

it('rejects control characters in paths before a command', function (Closure $call, string $exception) {
    $adapter = new FtpsFilesystemAdapter('127.0.0.1', 1, 'user', 'password');

    try {
        $call($adapter);
        $this->fail('The call did not fail.');
    } catch (Throwable $thrown) {
        expect($thrown)->toBeInstanceOf($exception)
            ->and($thrown->getPrevious())->toBeInstanceOf(InvalidArgumentException::class);
    }
})->with([
    'delete' => [fn (FtpsFilesystemAdapter $adapter) => $adapter->delete("backup.sql\r\nRMD /victim"), UnableToDeleteFile::class],
    'move' => [fn (FtpsFilesystemAdapter $adapter) => $adapter->move("backup.sql\nDELE x", 'other.sql', new Config), UnableToMoveFile::class],
]);
