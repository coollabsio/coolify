<?php

namespace App\Jobs;

use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

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
        return [new WithoutOverlapping($this->server->id)];
    }

    public function uniqueId(): int
    {
        return $this->server->id;
    }

    public function handle(): void
    {
        try {
            $container = getContainerStatus(server: $this->server, all_data: true, container_id: 'coolify-proxy', throwError: true);
            $status = $container['State']['Status'];
            if ($this->server->proxy->status !== $status) {
                $this->server->proxy->status = $status;
                if ($this->server->proxy->status === 'running') {
                    $traefik = $container['Config']['Labels']['org.opencontainers.image.title'];
                    $version = $container['Config']['Labels']['org.opencontainers.image.version'];
                    if (isset($version) && isset($traefik) && $traefik === 'Traefik' && Str::of($version)->startsWith('v2')) {
                        $this->server->proxy->type = ProxyTypes::TRAEFIK_V2->value;
                    }
                }
                $this->server->save();
            }
        } catch (\Exception $e) {
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
