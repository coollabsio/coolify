<?php

namespace App\Actions\Proxy;

use App\Actions\Proxy\SaveConfigurationSync;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Support\Str;

class CheckConfigurationSync
{
    public function __invoke(Server $server, bool $reset = false)
    {
        $proxy_path = get_proxy_path();
        $proxy_configuration = instant_remote_process([
            "cat $proxy_path/docker-compose.yml",
        ], $server, false);

        if ($reset || is_null($proxy_configuration)) {
            $proxy_configuration = Str::of(generate_default_proxy_configuration($server))->trim()->value;
            resolve(SaveConfigurationSync::class)($server, $proxy_configuration);
            return $proxy_configuration;
        }

        return $proxy_configuration;
    }

}