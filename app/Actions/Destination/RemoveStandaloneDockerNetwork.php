<?php

namespace App\Actions\Destination;

use App\Models\StandaloneDocker;

class RemoveStandaloneDockerNetwork
{
    public function handle(StandaloneDocker $destination): void
    {
        $safeNetwork = escapeshellarg($destination->network);

        instant_remote_process(["docker network disconnect {$safeNetwork} coolify-proxy"], $destination->server, throwError: false);
        instant_remote_process(["docker network rm -f {$safeNetwork}"], $destination->server);
    }
}
