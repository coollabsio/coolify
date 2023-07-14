<?php

namespace App\Jobs;

use App\Actions\Proxy\InstallProxy;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProxyCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected Server|null $server)
    {
    }
    public function handle()
    {
        try {
            $container_name = 'coolify-proxy';
            if ($this->server) {
                ray('Checking proxy for server: ' . $this->server->name);
                $status = get_container_status(server: $this->server, container_id: $container_name);
                if ($status === 'running') {
                    return;
                }
                resolve(InstallProxy::class)($this->server);
            } else {
                $servers = Server::whereRelation('settings', 'is_usable', true)->get();

                foreach ($servers as $server) {
                    $status = get_container_status(server: $server, container_id: $container_name);
                    if ($status === 'running') {
                        continue;
                    }
                    resolve(InstallProxy::class)($server);
                }
            }
        } catch (\Throwable $th) {
            ray($th->getMessage());
            //throw $th;
        }
    }
}
