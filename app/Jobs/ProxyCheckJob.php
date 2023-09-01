<?php

namespace App\Jobs;

use App\Actions\Proxy\StartProxy;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProxyCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
    }

    public function handle()
    {
        try {
            $container_name = 'coolify-proxy';
            $servers = Server::all();
            foreach ($servers as $server) {
                if (
                    $server->settings->is_reachable === false || $server->settings->is_usable === false
                ) {
                    continue;
                }
                $status = getContainerStatus(server: $server, container_id: $container_name);
                if ($status === 'running') {
                    continue;
                }
                if (data_get($server, 'proxy.type')) {
                    resolve(StartProxy::class)($server);
                }
            }
        } catch (\Throwable $th) {
            ray($th->getMessage());
            send_internal_notification('ProxyCheckJob failed with: ' . $th->getMessage());
            throw $th;
        }
    }
}
