<?php

namespace App\Events;

use App\Models\Server;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class S3DownloadFinished implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int|string|null $userId = null;

    public function __construct($teamId, $data = null)
    {
        if (is_null($data)) {
            return;
        }

        // Get userId from event data (the user who triggered the download)
        $this->userId = data_get($data, 'userId');

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

    public function broadcastOn(): ?array
    {
        if (is_null($this->userId)) {
            return [];
        }

        return [
            new PrivateChannel("user.{$this->userId}"),
        ];
    }
}
