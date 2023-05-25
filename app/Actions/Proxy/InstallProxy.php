<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Str;

class InstallProxy
{
    public function __invoke(Server $server): Activity
    {
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
        $server->extra_attributes->last_applied_proxy_settings = Str::of($docker_compose_yml_base64)->pipe('md5')->value;
        $server->save();

        // $env_file_base64 = base64_encode(
        //     $this->getEnvContents()
        // );
        $activity = remote_process([
            ...$create_networks_command,
            "echo 'Docker networks created...'",
            "mkdir -p $proxy_path",
            "cd $proxy_path",
            "echo '$docker_compose_yml_base64' | base64 -d > $proxy_path/docker-compose.yml",
            // "echo '$env_file_base64' | base64 -d > $proxy_path/.env",
            "echo 'Docker compose file created...'",
            "echo 'Pulling docker image...'",
            'docker compose pull -q',
            "echo 'Stopping proxy...'",
            'docker compose down -v --remove-orphans',
            "echo 'Starting proxy...'",
            'docker compose up -d --remove-orphans',
            "echo 'Proxy installed successfully...'"
        ], $server);

        return $activity;
    }

    // protected function getEnvContents()
    // {
    //     $data = [
    //         'LETS_ENCRYPT_EMAIL' => '',
    //     ];

    //     return collect($data)
    //         ->map(fn ($v, $k) => "{$k}={$v}")
    //         ->push(PHP_EOL)
    //         ->implode(PHP_EOL);
    // }
}
