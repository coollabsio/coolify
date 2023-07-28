<?php

namespace App\Actions\Proxy;

use App\Actions\Proxy\CheckConfigurationSync;
use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Str;

class StartProxy
{
    public function __invoke(Server $server): Activity
    {
        // TODO: check for other proxies
        if (is_null(data_get($server, 'proxy.type'))) {
            $server->proxy->type = ProxyTypes::TRAEFIK_V2->value;
            $server->proxy->status = ProxyStatus::EXITED->value;
            $server->save();
        }
        $proxy_path = get_proxy_path();
        $networks = collect($server->standaloneDockers)->map(function ($docker) {
            return $docker['network'];
        })->unique();
        if ($networks->count() === 0) {
            $networks = collect(['coolify']);
        }
        $create_networks_command = $networks->map(function ($network) {
            return "docker network ls --format '{{.Name}}' | grep '^$network$' >/dev/null 2>&1 || docker network create --attachable $network > /dev/null 2>&1";
        });

        $configuration = resolve(CheckConfigurationSync::class)($server);

        $docker_compose_yml_base64 = base64_encode($configuration);
        $server->proxy->last_applied_settings = Str::of($docker_compose_yml_base64)->pipe('md5')->value;
        $server->save();

        $activity = remote_process([
            "echo 'Creating required Docker networks...'",
            ...$create_networks_command,
            "cd $proxy_path",
            "echo 'Creating Docker Compose file...'",
            "echo 'Pulling docker image...'",
            'docker compose pull -q',
            "echo 'Stopping existing proxy...'",
            'docker compose down -v --remove-orphans',
            "lsof -nt -i:80 | xargs -r kill -9",
            "lsof -nt -i:443 | xargs -r kill -9",
            "echo 'Starting proxy...'",
            'docker compose up -d --remove-orphans',
            "echo 'Proxy installed successfully...'"
        ], $server);

        return $activity;
    }
}