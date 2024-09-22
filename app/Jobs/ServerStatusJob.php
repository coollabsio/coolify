<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ServerStatusJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int|string|null $disk_usage = null;

    public $tries = 3;

    public function backoff(): int
    {
        return isDev() ? 1 : 3;
    }

    public function __construct(public Server $server) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->server->uuid))];
    }

    public function uniqueId(): int
    {
        return $this->server->uuid;
    }

    public function handle()
    {
        if (! $this->server->isServerReady($this->tries)) {
            throw new \RuntimeException('Server is not ready.');
        }
        try {
            if ($this->server->isFunctional()) {
                $this->remove_unnecessary_coolify_yaml();
                if ($this->server->isSentinelEnabled()) {
                    $this->server->checkSentinel();
                }
            }
        } catch (\Throwable $e) {
            // send_internal_notification('ServerStatusJob failed with: '.$e->getMessage());
            ray($e->getMessage());

            return handleError($e);
        }

    }

    private function remove_unnecessary_coolify_yaml()
    {
        // This will remote the coolify.yaml file from the server as it is not needed on cloud servers
        if (isCloud() && $this->server->id !== 0) {
            $file = $this->server->proxyPath().'/dynamic/coolify.yaml';

            return instant_remote_process([
                "rm -f $file",
            ], $this->server, false);
        }
    }
}
