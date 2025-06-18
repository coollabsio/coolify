<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class SaveConfiguration
{
    use AsAction;

    public function handle(Server $server, ?string $proxy_settings = null)
    {
        if (is_null($proxy_settings)) {
            $proxy_settings = CheckConfiguration::run($server, true);
        }
        $proxy_path = $server->proxyPath();
        $docker_compose_yml_base64 = base64_encode($proxy_settings);

        $server->proxy->last_saved_settings = str($docker_compose_yml_base64)->pipe('md5')->value;
        $server->save();

        return instant_remote_process([
            "mkdir -p $proxy_path",
            "echo '$docker_compose_yml_base64' | base64 -d | tee $proxy_path/docker-compose.yml > /dev/null",
        ], $server);
    }
}
