<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChanged;
use App\Events\ProxyStatusChangedUI;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Activitylog\Models\Activity;

class StartProxy
{
    use AsAction;

    public function handle(Server $server, bool $async = true, bool $force = false, bool $restarting = false): string|Activity
    {
        $proxyType = $server->proxyType();
        if ((is_null($proxyType) || $proxyType === 'NONE' || $server->proxy->force_stop || $server->isBuildServer()) && $force === false) {
            return 'OK';
        }
        $server->proxy->set('status', 'starting');
        $server->save();
        $server->refresh();

        if (! $restarting) {
            ProxyStatusChangedUI::dispatch($server->team_id);
        }

        $commands = collect([]);
        $proxy_path = $server->proxyPath();
        $configuration = GetProxyConfiguration::run($server);
        if (! $configuration) {
            throw new \Exception('Configuration is not synced');
        }
        SaveProxyConfiguration::run($server, $configuration);
        $docker_compose_yml_base64 = base64_encode($configuration);
        $server->proxy->last_applied_settings = str($docker_compose_yml_base64)->pipe('md5')->value();
        $server->save();

        if ($server->isSwarmManager()) {
            $commands = $commands->merge([
                "mkdir -p $proxy_path/dynamic",
                "cd $proxy_path",
                "echo 'Creating required Docker Compose file.'",
                "echo 'Starting coolify-proxy.'",
                'docker stack deploy --detach=true -c docker-compose.yml coolify-proxy',
                "echo 'Successfully started coolify-proxy.'",
            ]);
        } else {
            if (isDev()) {
                if ($proxyType === ProxyTypes::CADDY->value) {
                    $proxy_path = '/data/coolify/proxy/caddy';
                }
            }
            $caddyfile_content = '{
    servers {
        protocols [h1 h2 h3]
    }
}

tls {
    protocols tls1.2 tls1.3
    ciphers TLS_ECDHE_ECDSA_WITH_AES_128_GCM_SHA256
            TLS_ECDHE_RSA_WITH_AES_128_GCM_SHA256
            TLS_ECDHE_ECDSA_WITH_AES_256_GCM_SHA384
            TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384
            TLS_ECDHE_ECDSA_WITH_CHACHA20_POLY1305
            TLS_ECDHE_RSA_WITH_CHACHA20_POLY1305
}

header {
    Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
}

import /dynamic/*.caddy';
            $caddyfile_base64 = base64_encode($caddyfile_content);
            $commands = $commands->merge([
                "mkdir -p $proxy_path/dynamic",
                "cd $proxy_path",
                "echo '$caddyfile_base64' | base64 -d | tee $proxy_path/dynamic/Caddyfile > /dev/null",
                "echo 'Creating required Docker Compose file.'",
                "echo 'Pulling docker image.'",
                'docker compose pull',
                'if docker ps -a --format "{{.Names}}" | grep -q "^coolify-proxy$"; then',
                "    echo 'Stopping and removing existing coolify-proxy.'",
                '    docker stop coolify-proxy 2>/dev/null || true',
                '    docker rm -f coolify-proxy 2>/dev/null || true',
                '    # Wait for container to be fully removed',
                '    for i in {1..10}; do',
                '        if ! docker ps -a --format "{{.Names}}" | grep -q "^coolify-proxy$"; then',
                '            break',
                '        fi',
                '        echo "Waiting for coolify-proxy to be removed... ($i/10)"',
                '        sleep 1',
                '    done',
                "    echo 'Successfully stopped and removed existing coolify-proxy.'",
                'fi',
            ]);
            // Ensure required networks exist BEFORE docker compose up (networks are declared as external)
            $commands = $commands->merge(ensureProxyNetworksExist($server));
            $commands = $commands->merge([
                "echo 'Starting coolify-proxy.'",
                'docker compose up -d --wait --remove-orphans',
                "echo 'Successfully started coolify-proxy.'",
            ]);
            $commands = $commands->merge(connectProxyToNetworks($server));
        }

        if ($async) {
            return remote_process($commands, $server, callEventOnFinish: 'ProxyStatusChanged', callEventData: $server->id);
        } else {
            instant_remote_process($commands, $server);

            $server->proxy->set('type', $proxyType);
            $server->save();
            ProxyStatusChanged::dispatch($server->id);

            return 'OK';
        }
    }
}
