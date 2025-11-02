<?php

namespace App\Events;

use App\Models\Server;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class S3DownloadFinished
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct($data)
    {
        $containerName = data_get($data, 'containerName');
        $serverId = data_get($data, 'serverId');

        if (filled($containerName) && filled($serverId)) {
            // Clean up the MinIO client container
            $commands = [];
            $commands[] = "docker stop {$containerName} 2>/dev/null || true";
            $commands[] = "docker rm {$containerName} 2>/dev/null || true";
            instant_remote_process($commands, Server::find($serverId), throwError: false);
        }
    }
}
