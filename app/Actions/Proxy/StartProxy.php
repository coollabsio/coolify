<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChanged;
use App\Events\ProxyStatusChangedUI;
use App\Models\Server;
use App\Services\ProxyPortParser;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Spatie\Activitylog\Models\Activity;

class StartProxy
{
    use AsAction;

    public function configureJob(JobDecorator $job): void
    {
        $job->onQueue(deployment_queue());
    }

    public function handle(Server $server, bool $async = true, bool $force = false, bool $restarting = false): string|Activity
    {
        $proxyType = $server->proxyType();
        if ((is_null($proxyType) || $proxyType === 'NONE' || $server->proxy->force_stop || $server->isBuildServer()) && $force === false) {
            return 'OK';
        }
        $configuration = GetProxyConfiguration::run($server);
        if (! $configuration) {
            throw new \Exception('Configuration is not synced');
        }
        ProxyPortParser::fromConfiguration($configuration);

        $server->proxy->set('status', 'starting');
        $server->save();
        $server->refresh();

        if (! $restarting) {
            ProxyStatusChangedUI::dispatch($server->team_id);
        }

        $commands = collect([]);
        $proxy_path = $server->proxyPath();
        // Absolute paths: a non-root SSH user may not be able to enter the proxy directory (#4255).
        $compose_file = rtrim($proxy_path, '/').'/docker-compose.yml';
        SaveProxyConfiguration::run($server, $configuration);
        $server->markProxyConfigurationApplied($configuration);

        if ($server->isSwarmManager()) {
            $commands = $commands->merge([
                "mkdir -p $proxy_path/dynamic",
                "echo 'Creating required Docker Compose file.'",
                "echo 'Starting coolify-proxy.'",
                "docker stack deploy --detach=true -c $compose_file coolify-proxy",
                "echo 'Successfully started coolify-proxy.'",
            ]);
        } else {
            $caddyfile = 'import /dynamic/*.caddy';
            $commands = $commands->merge([
                "mkdir -p $proxy_path/dynamic",
                "echo '$caddyfile' | tee $proxy_path/dynamic/Caddyfile > /dev/null",
                "echo 'Creating required Docker Compose file.'",
                "echo 'Pulling docker image.'",
                "docker compose -f $compose_file pull",
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
            if ($proxyType !== ProxyTypes::TRAEFIK->value) {
                // The sidecar belongs to the Traefik compose project, so --remove-orphans of another proxy keeps it.
                $commands->push('docker rm -f '.TRAEFIK_LOGROTATE_CONTAINER.' 2>/dev/null || true');
            }
            // Ensure required networks exist BEFORE docker compose up (networks are declared as external)
            $commands = $commands->merge(ensureProxyNetworksExist($server));
            $commands = $commands->merge([
                "echo 'Starting coolify-proxy.'",
                "docker compose -f $compose_file up -d --wait --remove-orphans",
                "echo 'Successfully started coolify-proxy.'",
            ]);
            $commands = $commands->merge(connectProxyToNetworks($server));
        }

        if ($async) {
            return remote_process($commands, $server, callEventOnFinish: 'ProxyStatusChanged', callEventData: $server->id, queue: deployment_queue());
        } else {
            instant_remote_process($commands, $server);

            $server->proxy->set('type', $proxyType);
            $server->save();
            ProxyStatusChanged::dispatch($server->id);

            return 'OK';
        }
    }
}
