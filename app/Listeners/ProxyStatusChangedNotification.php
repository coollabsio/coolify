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
        if (is_null($serverId)) {
            return;
        }
        $server = Server::where('id', $serverId)->first();
        if (is_null($server)) {
            return;
        }
        $proxyContainerName = 'coolify-proxy';
        $status = getContainerStatus($server, $proxyContainerName);
        $server->proxy->set('status', $status);
        $server->save();

        ProxyStatusChangedUI::dispatch($server->team_id);
        if ($status === 'running') {
            $server->setupDefaultRedirect();
            $server->setupDynamicProxyConfiguration();
            $server->proxy->force_stop = false;
            $server->save();
        }
        if ($status === 'created') {
            instant_remote_process([
                'docker rm -f coolify-proxy',
            ], $server);
        }
    }
}
