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
                $commands[] = 'docker exec '.escapeshellarg($container)." sh -c 'rm ".escapeshellarg($scriptPath)." 2>/dev/null || true'";
            }

            if (isSafeTmpPath($tmpPath)) {
                $commands[] = 'docker exec '.escapeshellarg($container)." sh -c 'rm ".escapeshellarg($tmpPath)." 2>/dev/null || true'";
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
