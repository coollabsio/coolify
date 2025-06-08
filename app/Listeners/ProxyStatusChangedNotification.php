<?php

namespace App\Listeners;

use App\Events\ProxyStatusChanged;
use App\Events\ProxyStatusChangedUI;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

class ProxyStatusChangedNotification implements ShouldQueueAfterCommit
{
    public function __construct() {}

    public function handle(ProxyStatusChanged $event)
    {
        $serverId = $event->data;
        $server = Server::find($serverId);
        if (is_null($server)) {
            return;
        }
        $proxyContainerName = 'coolify-proxy';
        $status = getContainerStatus($server, $proxyContainerName);
        $server->proxy->set('status', $status);
        $server->save();

        if ($status === 'running') {
            $server->setupDefaultRedirect();
            $server->setupDynamicProxyConfiguration();
            $server->proxy->force_stop = false;
            $server->save();
        }
        ProxyStatusChangedUI::dispatch($server->team_id);
    }
}
