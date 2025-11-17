<?php

namespace App\Events;

use App\Models\Server;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RestoreJobFinished
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct($data)
    {
        $scriptPath = data_get($data, 'scriptPath');
        $tmpPath = data_get($data, 'tmpPath');
        $container = data_get($data, 'container');
        $serverId = data_get($data, 'serverId');

        if (filled($container) && filled($serverId)) {
            $commands = [];

            if (isSafeTmpPath($scriptPath)) {
                $commands[] = "docker exec {$container} sh -c 'rm {$scriptPath} 2>/dev/null || true'";
            }

            if (isSafeTmpPath($tmpPath)) {
                $commands[] = "docker exec {$container} sh -c 'rm {$tmpPath} 2>/dev/null || true'";
            }

            if (! empty($commands)) {
                instant_remote_process($commands, Server::find($serverId), throwError: false);
            }
        }
    }
}
