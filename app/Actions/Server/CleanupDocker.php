<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class CleanupDocker
{
    use AsAction;

    public function handle(Server $server, bool $force = true)
    {
        if ($force) {
            instant_remote_process(['docker image prune -af'], $server, false);
            instant_remote_process(['docker container prune -f --filter "label=coolify.managed=true"'], $server, false);
            instant_remote_process(['docker builder prune -af'], $server, false);
        } else {
            instant_remote_process(['docker image prune -f'], $server, false);
            instant_remote_process(['docker container prune -f --filter "label=coolify.managed=true"'], $server, false);
            instant_remote_process(['docker builder prune -f'], $server, false);
        }
    }
}
