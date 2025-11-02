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
        $scriptPath = data_get($data, 'scriptPath');
        $tmpPath = data_get($data, 'tmpPath');
        $container = data_get($data, 'container');
        $serverId = data_get($data, 'serverId');
        $s3DownloadedFile = data_get($data, 's3DownloadedFile');

        // Clean up temporary files from container
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

        // Clean up S3 downloaded file from server
        if (filled($s3DownloadedFile) && filled($serverId)) {
            if (str($s3DownloadedFile)->startsWith('/tmp/s3-restore-')
                && ! str($s3DownloadedFile)->contains('..')
                && strlen($s3DownloadedFile) > 16  // longer than just "/tmp/s3-restore-"
            ) {
                $commands = [];
                $commands[] = "rm -f {$s3DownloadedFile}";
                instant_remote_process($commands, Server::find($serverId), throwError: false);
            }
        }
    }
}
