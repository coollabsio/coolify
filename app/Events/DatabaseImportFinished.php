<?php

namespace App\Events;

use App\Models\Server;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DatabaseImportFinished
{
    use Dispatchable, SerializesModels;

    public function __construct(array $data)
    {
        $commands = [];
        if (filled($data['containerName'] ?? null)) {
            $commands[] = 'docker rm -f '.escapeshellarg($data['containerName']).' 2>/dev/null || true';
        }
        if (isSafeTmpPath($data['serverTmpPath'] ?? null)) {
            $commands[] = 'rm -f '.escapeshellarg($data['serverTmpPath']).' 2>/dev/null || true';
        }
        if (filled($data['container'] ?? null)) {
            foreach (['containerTmpPath', 'scriptPath'] as $key) {
                if (isSafeTmpPath($data[$key] ?? null)) {
                    $commands[] = 'docker exec '.escapeshellarg($data['container']).' rm -f '.escapeshellarg($data[$key]).' 2>/dev/null || true';
                }
            }
        }
        $server = Server::find($data['serverId'] ?? null);
        if ($server && $commands !== []) {
            instant_remote_process($commands, $server, throwError: false);
        }
    }
}
