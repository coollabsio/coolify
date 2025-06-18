<?php

namespace App\Actions\Proxy;

use App\Events\ProxyStatusChanged;
use App\Events\ProxyStatusChangedUI;
use App\Models\Server;
use App\Services\ProxyDashboardCacheService;
use Lorisleiva\Actions\Concerns\AsAction;

class StopProxy
{
    use AsAction;

    public function handle(Server $server, bool $forceStop = true, int $timeout = 30)
    {
        try {
            $containerName = $server->isSwarm() ? 'coolify-proxy_traefik' : 'coolify-proxy';
            $server->proxy->status = 'stopping';
            $server->save();
            ProxyStatusChangedUI::dispatch($server->team_id);

            instant_remote_process(command: [
                "docker stop --time=$timeout $containerName",
                "docker rm -f $containerName",
            ], server: $server, throwError: false);

            $server->proxy->force_stop = $forceStop;
            $server->proxy->status = 'exited';
            $server->save();
        } catch (\Throwable $e) {
            return handleError($e);
        } finally {
            ProxyDashboardCacheService::clearCache($server);
            ProxyStatusChanged::dispatch($server->id);
        }
    }
}
