<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Events\ProxyStarted;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Activitylog\Models\Activity;

class StartProxy
{
    use AsAction;

    public function handle(Server $server, bool $async = true, bool $force = false): string|Activity
    {
        $proxyType = $server->proxyType();
        if ((is_null($proxyType) || $proxyType === 'NONE' || $server->proxy->force_stop || $server->isBuildServer()) && $force === false) {
            return 'OK';
        }
        $commands = collect([]);
        $proxy_path = $server->proxyPath();
        $configuration = CheckConfiguration::run($server);
        if (! $configuration) {
            throw new \Exception('Configuration is not synced');
        }
        SaveConfiguration::run($server, $configuration);
        $docker_compose_yml_base64 = base64_encode($configuration);
        $server->proxy->last_applied_settings = str($docker_compose_yml_base64)->pipe('md5')->value();
        $server->save();
        if ($server->isSwarm()) {
            $commands = $commands->merge([
                "mkdir -p $proxy_path/dynamic",
                "cd $proxy_path",
                "echo 'Creating required Docker Compose file.'",
                "echo 'Starting coolify-proxy.'",
                'docker stack deploy -c docker-compose.yml coolify-proxy',
                "echo 'Successfully started coolify-proxy.'",
            ]);
        } else {
            if (isDev()) {
                if ($proxyType === ProxyTypes::CADDY->value) {
                    $proxy_path = '/data/coolify/proxy/caddy';
                }
            }
            $caddyfile = 'import /dynamic/*.caddy';
            $commands = $commands->merge([
                "mkdir -p $proxy_path/dynamic",
                "cd $proxy_path",
                "echo '$caddyfile' > $proxy_path/dynamic/Caddyfile",
                "echo 'Creating required Docker Compose file.'",
                "echo 'Pulling docker image.'",
                'docker compose pull',
                'if docker ps -a --format "{{.Names}}" | grep -q "^coolify-proxy$"; then',
                "    echo 'Stopping and removing existing coolify-proxy.'",
                '    docker rm -f coolify-proxy || true',
                "    echo 'Successfully stopped and removed existing coolify-proxy.'",
                'fi',
                "echo 'Starting coolify-proxy.'",
                'docker compose up -d --remove-orphans',
                "echo 'Successfully started coolify-proxy.'",
            ]);
            $commands = $commands->merge(connectProxyToNetworks($server));
        }

        if ($async) {
            return remote_process($commands, $server, callEventOnFinish: 'ProxyStarted', callEventData: $server);
        } else {
            instant_remote_process($commands, $server);
            $server->proxy->set('status', 'running');
            $server->proxy->set('type', $proxyType);
            $server->save();
            ProxyStarted::dispatch($server);

            return 'OK';
        }
    }
}
