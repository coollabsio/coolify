<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class CleanupDocker
{
    use AsAction;

    public function handle(Server $server, bool $force = true)
    {

        // cleanup docker images, containers, and builder caches
        if ($force) {
            instant_remote_process(['docker image prune -af'], $server, false);
            instant_remote_process(['docker container prune -f --filter "label=coolify.managed=true"'], $server, false);
            instant_remote_process(['docker builder prune -af'], $server, false);
        } else {
            instant_remote_process(['docker image prune -f'], $server, false);
            instant_remote_process(['docker container prune -f --filter "label=coolify.managed=true"'], $server, false);
            instant_remote_process(['docker builder prune -f'], $server, false);
        }
        // cleanup networks
        // $networks = collectDockerNetworksByServer($server);
        // $proxyNetworks = collectProxyDockerNetworksByServer($server);
        // $diff = $proxyNetworks->diff($networks);
        // if ($diff->count() > 0) {
        //     $diff->map(function ($network) use ($server) {
        //         instant_remote_process(["docker network disconnect $network coolify-proxy"], $server);
        //         instant_remote_process(["docker network rm $network"], $server);
        //     });
        // }
    }
}
