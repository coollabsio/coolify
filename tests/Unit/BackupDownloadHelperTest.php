<?php

use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\StreamedResponse;

function backupDownloadServer(): Server
{
    $server = new Server;
    $server->forceFill([
        'ip' => '192.0.2.10',
        'port' => 2222,
        'user' => 'root',
    ]);

    $privateKey = Mockery::mock(PrivateKey::class);
    $privateKey->shouldReceive('getKeyLocation')->andReturn('/tmp/private-key');
    $server->setRelation('privateKey', $privateKey);

    return $server;
}

it('throws when the backup file does not exist on the server', function () {
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')
        ->once()
        ->with('/backups/archive.tar.gz')
        ->andReturnFalse();

    Storage::shouldReceive('build')
        ->once()
        ->with([
            'driver' => 'sftp',
            'host' => '192.0.2.10',
            'port' => 2222,
            'username' => 'root',
            'privateKey' => '/tmp/private-key',
            'root' => '/',
        ])
        ->andReturn($disk);

    streamBackupFromServer(backupDownloadServer(), '/backups/archive.tar.gz', 'application/gzip');
})->throws(FileNotFoundException::class);

it('streams a backup file with the requested content type', function () {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, 'backup contents');
    rewind($stream);

    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->andReturnTrue();
    $disk->shouldReceive('readStream')
        ->once()
        ->with('/backups/archive.tar.gz')
        ->andReturn($stream);
    Storage::shouldReceive('build')->once()->andReturn($disk);

    $response = streamBackupFromServer(backupDownloadServer(), '/backups/archive.tar.gz', 'application/gzip');

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('content-type'))->toBe('application/gzip')
        ->and($response->headers->get('content-disposition'))->toBe('attachment; filename="archive.tar.gz"');

    ob_start();
    ob_start();
    $response->sendContent();
    $contents = ob_get_clean();

    expect($contents)->toBe('backup contents');
});
