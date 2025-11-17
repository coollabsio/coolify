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

        // Clean up helper container and temporary files
        if (filled($serverId)) {
            $commands = [];

            // Stop and remove helper container
            if (filled($containerName)) {
                $commands[] = "docker rm -f {$containerName} 2>/dev/null || true";
            }

            // Clean up downloaded file from server /tmp
            if (isSafeTmpPath($serverTmpPath)) {
                $commands[] = "rm -f {$serverTmpPath} 2>/dev/null || true";
            }

            // Clean up script from server
            if (isSafeTmpPath($scriptPath)) {
                $commands[] = "rm -f {$scriptPath} 2>/dev/null || true";
            }

            // Clean up files from database container
            if (filled($container)) {
                if (isSafeTmpPath($containerTmpPath)) {
                    $commands[] = "docker exec {$container} rm -f {$containerTmpPath} 2>/dev/null || true";
                }
                if (isSafeTmpPath($scriptPath)) {
                    $commands[] = "docker exec {$container} rm -f {$scriptPath} 2>/dev/null || true";
                }
            }

            instant_remote_process($commands, Server::find($serverId), throwError: false);
        }
    }
}
