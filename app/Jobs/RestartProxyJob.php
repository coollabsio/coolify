<?php

namespace App\Jobs;

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Actions\Proxy\StartProxy;
use App\Events\ProxyStatusChangedUI;
use App\Models\Server;
use App\Services\ProxyDashboardCacheService;
use App\Services\ProxyPortParser;
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

    public function __construct(public Server $server)
    {
        $this->onQueue(deployment_queue());
    }

    public function handle()
    {
        try {
            $configuration = GetProxyConfiguration::run($this->server);
            if (! $configuration) {
                throw new \Exception('Configuration is not synced');
            }
            ProxyPortParser::fromConfiguration($configuration);

            $this->server->markProxyTransitionStarted();

            // Set status to restarting
            $this->server->proxy->status = 'restarting';
            $this->server->proxy->force_stop = false;
            $this->server->save();

            // Build combined stop + start commands for a single activity
            $commands = $this->buildRestartCommands($configuration);

            // Create activity and dispatch immediately - returns Activity right away
            // The remote_process runs asynchronously, so UI gets activity ID instantly
            $activity = remote_process(
                $commands,
                $this->server,
                callEventOnFinish: 'ProxyStatusChanged',
                callEventData: $this->server->id,
                queue: deployment_queue(),
            );

            // Store activity ID and notify UI immediately with it
            $this->activity_id = $activity->id;
            ProxyStatusChangedUI::dispatch($this->server->team_id, $this->activity_id);

        } catch (\InvalidArgumentException $e) {
            $this->server->markProxyTransitionFinished();

            return handleError($e);
        } catch (\Throwable $e) {
            $this->server->markProxyTransitionFinished();

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
    private function buildRestartCommands(string $configuration): array
    {
        $containerName = $this->server->isSwarm() ? 'coolify-proxy_traefik' : 'coolify-proxy';
        $stopTimeout = 30;

        SaveProxyConfiguration::run($this->server, $configuration);
        $this->server->markProxyConfigurationApplied($configuration);

        $commands = collect([]);

        // === STOP PHASE ===
        $commands = $commands->merge([
            "echo 'Stopping proxy...'",
            dockerStopCommand($stopTimeout, $containerName, $this->server).' 2>/dev/null || true',
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
        $commands = $commands->merge(StartProxy::startCommands($this->server));

        return $commands->toArray();
    }
}
