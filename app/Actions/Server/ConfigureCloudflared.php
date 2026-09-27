<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;

class ConfigureCloudflared
{
    use AsAction;

    public function handle(Server $server, string $cloudflare_token, string $ssh_domain = '', bool $enableHttpOrigin = false, bool $skipSsh = false, string $containerName = 'coolify-cloudflared'): Activity
    {
        try {
            $metrics = $containerName === 'coolify-http-cloudflared' ? '127.0.0.1:60124' : '127.0.0.1:60123';
            $config = [
                'services' => [
                    'coolify-cloudflared' => [
                        'container_name' => $containerName,
                        'image' => 'cloudflare/cloudflared:latest',
                        'restart' => RESTART_MODE,
                        'network_mode' => 'host',
                        'command' => 'tunnel run',
                        'environment' => [
                            "TUNNEL_TOKEN={$cloudflare_token}",
                            "TUNNEL_METRICS={$metrics}",
                        ],
                        'healthcheck' => [
                            'test' => ['CMD', 'cloudflared', 'tunnel', '--metrics', $metrics, 'ready'],
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
                "echo 'Stopping existing Cloudflare Tunnel container {$containerName}.'",
                "docker rm -f {$containerName} || true",
                "echo 'Starting new Cloudflare Tunnel container {$containerName}.'",
                "docker compose up --wait --wait-timeout 15 --remove-orphans || docker logs {$containerName}",
            ]);

            return remote_process($commands, $server, callEventOnFinish: 'CloudflareTunnelChanged', callEventData: [
                'server_id' => $server->id,
                'ssh_domain' => $ssh_domain,
                'enable_http_origin' => $enableHttpOrigin,
                'skip_ssh' => $skipSsh,
                'container_name' => $containerName,
            ]);
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
