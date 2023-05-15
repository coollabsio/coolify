<?php

namespace App\Jobs;

use App\Actions\Proxy\InstallProxy;
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
            $configuration_path = config('coolify.proxy_config_path');
            $servers = Server::whereRelation('settings', 'is_validated', true)->get();

            foreach ($servers as $server) {
                $status = checkContainerStatus(server: $server, container_id: $container_name);
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
