<?php

namespace App\Events;

use App\Models\Server;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class S3RestoreJobFinished
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct($data)
    {
        $containerName = data_get($data, 'containerName');
        $serverTmpPath = data_get($data, 'serverTmpPath');
        $scriptPath = data_get($data, 'scriptPath');
        $containerTmpPath = data_get($data, 'containerTmpPath');
        $container = data_get($data, 'container');
        $serverId = data_get($data, 'serverId');

        // Most cleanup now happens inline during restore process
        // This acts as a safety net for edge cases (errors, interruptions)
        if (filled($serverId)) {
            $commands = [];

            // Ensure helper container is removed (may already be gone from inline cleanup)
            if (filled($containerName)) {
                $commands[] = 'docker rm -f '.escapeshellarg($containerName).' 2>/dev/null || true';
            }

            // Clean up server temp file if still exists (should already be cleaned)
            if (isSafeTmpPath($serverTmpPath)) {
                $commands[] = 'rm -f '.escapeshellarg($serverTmpPath).' 2>/dev/null || true';
            }

            // Clean up any remaining files in database container (may already be cleaned)
            if (filled($container)) {
                if (isSafeTmpPath($containerTmpPath)) {
                    $commands[] = 'docker exec '.escapeshellarg($container).' rm -f '.escapeshellarg($containerTmpPath).' 2>/dev/null || true';
                }
                if (isSafeTmpPath($scriptPath)) {
                    $commands[] = 'docker exec '.escapeshellarg($container).' rm -f '.escapeshellarg($scriptPath).' 2>/dev/null || true';
                }
            }

            if (! empty($commands)) {
                $server = Server::find($serverId);
                if ($server) {
                    instant_remote_process($commands, $server, throwError: false);
                }
            }
        }
    }
}
