<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class ConfigureCloudflared
{
    use AsAction;

    public function handle(Server $server, string $cloudflare_token)
    {
        try {
            $config = [
                'services' => [
                    'coolify-cloudflared' => [
                        'container_name' => 'coolify-cloudflared',
                        'image' => 'cloudflare/cloudflared:latest',
                        'restart' => RESTART_MODE,
                        'network_mode' => 'host',
                        'command' => 'tunnel run',
                        'environment' => [
                            "TUNNEL_TOKEN={$cloudflare_token}",
                        ],
                    ],
                ],
            ];
            $config = Yaml::dump($config, 12, 2);
            $docker_compose_yml_base64 = base64_encode($config);
            $commands = collect([
                'mkdir -p /tmp/cloudflared',
                'cd /tmp/cloudflared',
                "echo '$docker_compose_yml_base64' | base64 -d | tee docker-compose.yml > /dev/null",
                'docker compose pull',
                'docker compose down -v --remove-orphans > /dev/null 2>&1',
                'docker compose up -d --remove-orphans',
            ]);
            instant_remote_process($commands, $server);
        } catch (\Throwable $e) {
            ray($e);
            throw $e;
        } finally {
            $commands = collect([
                'rm -fr /tmp/cloudflared',
            ]);
            instant_remote_process($commands, $server);
        }
    }
}
