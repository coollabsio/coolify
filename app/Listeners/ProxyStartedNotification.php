<?php

namespace App\Listeners;

use App\Events\ProxyStarted;
use App\Models\Server;

class ProxyStartedNotification
{
    public Server $server;

    public function __construct() {}

    public function handle(ProxyStarted $event): void
    {
        $this->server = data_get($event, 'data');
        $this->server->setupDefaultRedirect();
        $this->server->setupDynamicProxyConfiguration();
        $this->server->proxy->force_stop = false;
        $this->server->save();
    }
}
