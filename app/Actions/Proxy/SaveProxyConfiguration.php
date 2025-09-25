<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class SaveProxyConfiguration
{
    use AsAction;

    public function handle(Server $server, string $configuration): void
    {
        $proxy_path = $server->proxyPath();
        $docker_compose_yml_base64 = base64_encode($configuration);

        // Update the saved settings hash
        $server->proxy->last_saved_settings = str($docker_compose_yml_base64)->pipe('md5')->value;
        $server->save();

        // Transfer the configuration file to the server
        instant_remote_process([
            "mkdir -p $proxy_path",
            "echo '$docker_compose_yml_base64' | base64 -d | tee $proxy_path/docker-compose.yml > /dev/null",
        ], $server);
    }
}
