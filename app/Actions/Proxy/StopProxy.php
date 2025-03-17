<?php

namespace App\Actions\Proxy;

use App\Events\ProxyStatusChanged;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class StopProxy
{
    use AsAction;

    public function handle(Server $server)
    {
        try {
            $containerName = $server->isSwarm() ? 'coolify-proxy_traefik' : 'coolify-proxy';
            instant_remote_process(["docker rm -f $containerName"], $server);
            $server->proxy->force_stop = true;
            $server->proxy->status = 'exited';
            $server->save();
            ProxyStatusChanged::dispatch($server->team_id);
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }
}
