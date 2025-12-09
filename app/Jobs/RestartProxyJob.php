<?php

namespace App\Jobs;

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChangedUI;
use App\Models\Server;
use App\Services\ProxyDashboardCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RestartProxyJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 120;

    public ?int $activity_id = null;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('restart-proxy-'.$this->server->uuid))->expireAfter(120)->dontRelease()];
    }

    public function __construct(public Server $server) {}

    public function handle()
    {
        try {
            // Set status to restarting
            $this->server->proxy->status = 'restarting';
            $this->server->proxy->force_stop = false;
            $this->server->save();

            // Build combined stop + start commands for a single activity
            $commands = $this->buildRestartCommands();

            // Create activity and dispatch immediately - returns Activity right away
            // The remote_process runs asynchronously, so UI gets activity ID instantly
            $activity = remote_process(
                $commands,
                $this->server,
                callEventOnFinish: 'ProxyStatusChanged',
                callEventData: $this->server->id
            );

            // Store activity ID and notify UI immediately with it
            $this->activity_id = $activity->id;
            ProxyStatusChangedUI::dispatch($this->server->team_id, $this->activity_id);

        } catch (\Throwable $e) {
            // Set error status
            $this->server->proxy->status = 'error';
            $this->server->save();

            // Notify UI of error
            ProxyStatusChangedUI::dispatch($this->server->team_id);

            // Clear dashboard cache on error
            ProxyDashboardCacheService::clearCache($this->server);

            return handleError($e);
        }
    }

    /**
     * Build combined stop + start commands for proxy restart.
     * This creates a single command sequence that shows all logs in one activity.
     */
    private function buildRestartCommands(): array
    {
        $proxyType = $this->server->proxyType();
        $containerName = $this->server->isSwarm() ? 'coolify-proxy_traefik' : 'coolify-proxy';
        $proxy_path = $this->server->proxyPath();
        $stopTimeout = 30;

        // Get proxy configuration
        $configuration = GetProxyConfiguration::run($this->server);
        if (! $configuration) {
            throw new \Exception('Configuration is not synced');
        }
        SaveProxyConfiguration::run($this->server, $configuration);
        $docker_compose_yml_base64 = base64_encode($configuration);
        $this->server->proxy->last_applied_settings = str($docker_compose_yml_base64)->pipe('md5')->value();
        $this->server->save();

        $commands = collect([]);

        // === STOP PHASE ===
        $commands = $commands->merge([
            "echo 'Stopping proxy...'",
            "docker stop -t=$stopTimeout $containerName 2>/dev/null || true",
            "docker rm -f $containerName 2>/dev/null || true",
            '# Wait for container to be fully removed',
            'for i in {1..15}; do',
            "    if ! docker ps -a --format \"{{.Names}}\" | grep -q \"^$containerName$\"; then",
            "        echo 'Container removed successfully.'",
            '        break',
            '    fi',
            '    echo "Waiting for container to be removed... ($i/15)"',
            '    sleep 1',
            '    # Force remove on each iteration in case it got stuck',
            "    docker rm -f $containerName 2>/dev/null || true",
            'done',
            '# Final verification and force cleanup',
            "if docker ps -a --format \"{{.Names}}\" | grep -q \"^$containerName$\"; then",
            "    echo 'Container still exists after wait, forcing removal...'",
            "    docker rm -f $containerName 2>/dev/null || true",
            '    sleep 2',
            'fi',
            "echo 'Proxy stopped successfully.'",
        ]);

        // === START PHASE ===
        if ($this->server->isSwarmManager()) {
            $commands = $commands->merge([
                "echo 'Starting proxy (Swarm mode)...'",
                "mkdir -p $proxy_path/dynamic",
                "cd $proxy_path",
                "echo 'Creating required Docker Compose file.'",
                "echo 'Starting coolify-proxy.'",
                'docker stack deploy --detach=true -c docker-compose.yml coolify-proxy',
                "echo 'Successfully started coolify-proxy.'",
            ]);
        } else {
            if (isDev() && $proxyType === ProxyTypes::CADDY->value) {
                $proxy_path = '/data/coolify/proxy/caddy';
            }
            $caddyfile = 'import /dynamic/*.caddy';
            $commands = $commands->merge([
                "echo 'Starting proxy...'",
                "mkdir -p $proxy_path/dynamic",
                "cd $proxy_path",
                "echo '$caddyfile' > $proxy_path/dynamic/Caddyfile",
                "echo 'Creating required Docker Compose file.'",
                "echo 'Pulling docker image.'",
                'docker compose pull',
            ]);
            // Ensure required networks exist BEFORE docker compose up
            $commands = $commands->merge(ensureProxyNetworksExist($this->server));
            $commands = $commands->merge([
                "echo 'Starting coolify-proxy.'",
                'docker compose up -d --wait --remove-orphans',
                "echo 'Successfully started coolify-proxy.'",
            ]);
            $commands = $commands->merge(connectProxyToNetworks($this->server));
        }

        return $commands->toArray();
    }
}
