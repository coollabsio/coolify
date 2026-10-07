<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

class UpdateCloudflared
{
    use AsAction;

    /**
     * Pulls the latest cloudflared image and recreates the container from its existing Compose file.
     *
     * Coolify usually reaches the server through this tunnel, so the recreate runs detached on the
     * server: the SSH session ends before the old connector stops, and the tunnel reconnects after it.
     */
    public function handle(Server $server): Activity
    {
        $directory = $this->findConfigurationDirectory($server);

        return remote_process([
            "cd {$directory}",
            'echo Pulling latest Cloudflare Tunnel image.',
            'docker compose pull',
            'echo Recreating the Cloudflare Tunnel container in the background. SSH through the tunnel reconnects in a few seconds.',
            "nohup setsid sh -c 'sleep 2; docker compose up -d --force-recreate --remove-orphans' > /dev/null 2>&1 < /dev/null &",
        ], $server);
    }

    /**
     * Older Coolify versions wrote the Compose file to /tmp, which some systems clear on reboot.
     */
    private function findConfigurationDirectory(Server $server): string
    {
        $directory = instant_remote_process([
            'for dir in '.ConfigureCloudflared::DIRECTORY.' /tmp/cloudflared; do if [ -f "$dir/docker-compose.yml" ]; then echo "$dir"; break; fi; done',
        ], $server, false);

        if (blank($directory)) {
            throw new RuntimeException('The cloudflared configuration was not found on the server. Configure the tunnel again with the automated setup.');
        }

        return trim($directory);
    }
}
