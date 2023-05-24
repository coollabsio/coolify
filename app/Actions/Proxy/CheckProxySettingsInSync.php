<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Support\Str;

class CheckProxySettingsInSync
{
    public function __invoke(Server $server, bool $reset = false)
    {
        $proxy_path = config('coolify.proxy_config_path');
        $output = instant_remote_process([
            "cat $proxy_path/docker-compose.yml",
        ], $server, false);
        if (is_null($output) || $reset) {
            $final_output = Str::of(getProxyConfiguration($server))->trim()->value;
        } else {
            $final_output = Str::of($output)->trim()->value;
        }
        $docker_compose_yml_base64 = base64_encode($final_output);
        $server->extra_attributes->last_saved_proxy_settings = Str::of($docker_compose_yml_base64)->pipe('md5')->value;
        $server->save();
        if (is_null($output) || $reset) {
            instant_remote_process([
                "mkdir -p $proxy_path",
                "echo '$docker_compose_yml_base64' | base64 -d > $proxy_path/docker-compose.yml",
            ], $server);
        }
        return $final_output;
    }
}
