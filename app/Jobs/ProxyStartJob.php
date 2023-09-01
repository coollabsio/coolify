<?php

namespace App\Jobs;

use App\Actions\Proxy\StartProxy;
use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProxyStartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected Server $server)
    {
    }

    public function handle()
    {
        try {
            $container_name = 'coolify-proxy';
            ray('Starting proxy for server: ' . $this->server->name);
            $status = getContainerStatus(server: $this->server, container_id: $container_name);
            if ($status === 'running') {
                return;
            }
            if (is_null(data_get($this->server, 'proxy.type'))) {
                $this->server->proxy->type = ProxyTypes::TRAEFIK_V2->value;
                $this->server->proxy->status = ProxyStatus::EXITED->value;
                $this->server->save();
            }
            resolve(StartProxy::class)($this->server);
        } catch (\Throwable $th) {
            send_internal_notification('ProxyStartJob failed with: ' . $th->getMessage());
            ray($th->getMessage());
            throw $th;
        }
    }
}
