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
        if (filled($scriptPath) && filled($tmpPath) && filled($container) && filled($serverId)) {
            if (str($tmpPath)->startsWith('/tmp/')
                && str($scriptPath)->startsWith('/tmp/')
                && ! str($tmpPath)->contains('..')
                && ! str($scriptPath)->contains('..')
                && strlen($tmpPath) > 5  // longer than just "/tmp/"
                && strlen($scriptPath) > 5
            ) {
                $commands[] = "docker exec {$container} sh -c 'rm {$scriptPath}'";
                $commands[] = "docker exec {$container} sh -c 'rm {$tmpPath}'";
                instant_remote_process($commands, Server::find($serverId), throwError: true);
            }
        }
    }
}
