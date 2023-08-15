<?php

namespace App\Jobs;

use App\Actions\Proxy\StartProxy;
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
            $status = get_container_status(server: $this->server, container_id: $container_name);
            if ($status === 'running') {
                return;
            }
            resolve(StartProxy::class)($this->server);
        } catch (\Throwable $th) {
            ray($th->getMessage());
            //throw $th;
        }
    }
}
