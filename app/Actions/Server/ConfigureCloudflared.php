<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;

class ConfigureCloudflared
{
    use AsAction;

    public function handle(Server $server, string $cloudflare_token, string $ssh_domain): Activity
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
                            'TUNNEL_METRICS=127.0.0.1:60123',
                        ],
                        'healthcheck' => [
                            'test' => ['CMD', 'cloudflared', 'tunnel', '--metrics', '127.0.0.1:60123', 'ready'],
                            'interval' => '5s',
                            'timeout' => '30s',
                            'retries' => 5,
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
                'echo Pulling latest Cloudflare Tunnel image.',
                'docker compose pull',
                'echo Stopping existing Cloudflare Tunnel container.',
                'docker rm -f coolify-cloudflared || true',
                'echo Starting new Cloudflare Tunnel container.',
                'docker compose up --wait --wait-timeout 15 --remove-orphans || docker logs coolify-cloudflared',
            ]);

            return remote_process($commands, $server, callEventOnFinish: 'CloudflareTunnelChanged', callEventData: [
                'server_id' => $server->id,
                'ssh_domain' => $ssh_domain,
            ]);
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
