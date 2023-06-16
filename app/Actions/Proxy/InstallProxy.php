<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Str;

class InstallProxy
{
    public function __invoke(Server $server): Activity
    {
        if (is_null(data_get($server, 'extra_attributes.proxy_type'))) {
            $server->extra_attributes->proxy_type = ProxyTypes::TRAEFIK_V2->value;
            $server->extra_attributes->proxy_status = ProxyStatus::EXITED->value;
            $server->save();
        }
        $proxy_path = config('coolify.proxy_config_path');

        $networks = collect($server->standaloneDockers)->map(function ($docker) {
            return $docker['network'];
        })->unique();
        if ($networks->count() === 0) {
            $networks = collect(['coolify']);
        }
        $create_networks_command = $networks->map(function ($network) {
            return "docker network ls --format '{{.Name}}' | grep '^$network$' >/dev/null 2>&1 || docker network create --attachable $network > /dev/null 2>&1";
        });

        $configuration = instant_remote_process([
            "cat $proxy_path/docker-compose.yml",
        ], $server, false);
        if (is_null($configuration)) {
            $configuration = Str::of(getProxyConfiguration($server))->trim()->value;
        } else {
            $configuration = Str::of($configuration)->trim()->value;
        }
        $docker_compose_yml_base64 = base64_encode($configuration);
        $server->extra_attributes->proxy_last_applied_settings = Str::of($docker_compose_yml_base64)->pipe('md5')->value;
        $server->save();
        $activity = remote_process([
            "echo 'Creating required Docker networks...'",
            ...$create_networks_command,
            "mkdir -p $proxy_path",
            "cd $proxy_path",
            "echo '$docker_compose_yml_base64' | base64 -d > $proxy_path/docker-compose.yml",
            "echo 'Creating Docker Compose file...'",
            "echo 'Pulling docker image...'",
            'docker compose pull -q',
            "echo 'Stopping old proxy...'",
            'docker compose down -v --remove-orphans',
            "echo 'Starting new proxy...'",
            'docker compose up -d --remove-orphans',
            "echo 'Proxy installed successfully...'"
        ], $server);

        return $activity;
    }
}
