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

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        try {
            $container_name = 'coolify-proxy';
            $servers = Server::whereRelation('settings', 'is_usable', true)->where('proxy->type', ProxyTypes::TRAEFIK_V2)->get();

            foreach ($servers as $server) {
                $status = get_container_status(server: $server, container_id: $container_name);
                if ($status === 'running') {
                    continue;
                }
                resolve(InstallProxy::class)($server);
            }
        } catch (\Throwable $th) {
            //throw $th;
        }
    }
}
