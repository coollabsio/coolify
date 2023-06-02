<?php

namespace App\Jobs;

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Str;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProxyContainerStatusJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Server $server;
    public $tries = 1;
    public $timeout = 120;
    public function middleware(): array
    {
        return [new WithoutOverlapping($this->server->id)];
    }
    public function __construct(Server $server)
    {
        $this->server = $server;
    }
    public function uniqueId(): int
    {
        return $this->server->id;
    }
    public function handle(): void
    {
        try {
            $container = get_container_status(server: $this->server, all_data: true, container_id: 'coolify-proxy', throwError: false);
            $status = $container['State']['Status'];
            if ($this->server->extra_attributes->proxy_status !== $status) {
                $this->server->extra_attributes->proxy_status = $status;
                if ($this->server->extra_attributes->proxy_status === 'running') {
                    $traefik = $container['Config']['Labels']['org.opencontainers.image.title'];
                    $version = $container['Config']['Labels']['org.opencontainers.image.version'];
                    if (isset($version) && isset($traefik) && $traefik === 'Traefik' && Str::of($version)->startsWith('v2')) {
                        $this->server->extra_attributes->proxy_type = ProxyTypes::TRAEFIK_V2->value;
                    }
                }
                $this->server->save();
            }
        } catch (\Exception $e) {
            ray($e->getMessage());
        }
    }
}
