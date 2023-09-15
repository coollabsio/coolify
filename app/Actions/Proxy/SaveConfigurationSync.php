<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use Illuminate\Support\Str;

class SaveConfigurationSync
{
    public function __invoke(Server $server)
    {
        try {
            $proxy_settings = resolve(CheckConfigurationSync::class)($server, true);
            $proxy_path = get_proxy_path();
            $docker_compose_yml_base64 = base64_encode($proxy_settings);

            $server->proxy->last_saved_settings = Str::of($docker_compose_yml_base64)->pipe('md5')->value;
            $server->save();

            instant_remote_process([
                "mkdir -p $proxy_path",
                "echo '$docker_compose_yml_base64' | base64 -d > $proxy_path/docker-compose.yml",
            ], $server);
        } catch (\Throwable $e) {
            ray($e);
        }

    }
}
