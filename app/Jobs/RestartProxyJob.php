<?php

namespace App\Jobs;

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Actions\Proxy\StopProxy;
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

    public $timeout = 60;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('restart-proxy-'.$this->server->uuid))->dontRelease()];
    }

    public function __construct(public Server $server) {}

    public function handle()
    {
        try {
            StopProxy::run($this->server);

            $this->server->proxy->force_stop = false;
            $this->server->save();

            StartProxy::run($this->server, force: true);

            // Clear Traefik dashboard cache after proxy restart
            ProxyDashboardCacheService::clearCache($this->server);

            // CheckProxy::run($this->server, true);
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }
}
