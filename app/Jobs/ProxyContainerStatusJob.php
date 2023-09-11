<?php

namespace App\Jobs;

use App\Actions\Proxy\StartProxy;
use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProxyContainerStatusJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Server $server;
    public $tries = 1;
    public $timeout = 120;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    public function middleware(): array
    {
        return [new WithoutOverlapping($this->server->uuid)];
    }

    public function uniqueId(): string
    {
        ray($this->server->uuid);
        return $this->server->uuid;
    }

    public function handle(): void
    {
        try {
            $proxyType = data_get($this->server, 'proxy.type');
            if ($proxyType === ProxyTypes::NONE->value) {
                return;
            }
            if (is_null($proxyType)) {
                if ($this->server->isProxyShouldRun()) {
                    $this->server->proxy->type = ProxyTypes::TRAEFIK_V2->value;
                    $this->server->proxy->status = ProxyStatus::EXITED->value;
                    $this->server->save();
                    resolve(StartProxy::class)($this->server);
                    return;
                }
            }

            $container = getContainerStatus(server: $this->server, all_data: true, container_id: 'coolify-proxy', throwError: false);
            $containerStatus = data_get($container, 'State.Status');
            $databaseContainerStatus = data_get($this->server, 'proxy.status', 'exited');


            if ($proxyType !== ProxyTypes::NONE->value) {
                if ($containerStatus === 'running') {
                    $this->server->proxy->status = $containerStatus;
                    $this->server->save();
                    return;
                }
                if ((is_null($containerStatus) ||$containerStatus !== 'running' || $databaseContainerStatus !== 'running' || ($containerStatus && $databaseContainerStatus !== $containerStatus)) && $this->server->isProxyShouldRun()) {
                    $this->server->proxy->status = $containerStatus;
                    $this->server->save();
                    resolve(StartProxy::class)($this->server);
                    return;
                }
            }
        } catch (\Throwable $e) {
            if ($e->getCode() === 1) {
                $this->server->proxy->status = 'exited';
                $this->server->save();
            }
            send_internal_notification('ProxyContainerStatusJob failed with: ' . $e->getMessage());
            ray($e->getMessage());
            throw $e;
        }
    }
}
