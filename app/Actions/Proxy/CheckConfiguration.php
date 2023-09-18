<?php

namespace App\Actions\Proxy;

use Lorisleiva\Actions\Concerns\AsAction;
use App\Models\Server;
use Illuminate\Support\Str;

class CheckConfiguration
{
    use AsAction;
    public function handle(Server $server, bool $reset = false)
    {
        $proxy_path = get_proxy_path();
        $proxy_configuration = instant_remote_process([
            "cat $proxy_path/docker-compose.yml",
        ], $server, false);

        if ($reset || !$proxy_configuration || is_null($proxy_configuration)) {
            $proxy_configuration = Str::of(generate_default_proxy_configuration($server))->trim()->value;
        }
        if (!$proxy_configuration || is_null($proxy_configuration)) {
            throw new \Exception("Could not generate proxy configuration");
        }
        return $proxy_configuration;
    }
}
