<?php

namespace App\Jobs;

use App\Actions\Docker\GetContainersStatus;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ContainerStatusJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 4;

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
        GetContainersStatus::run($this->server);
    }
}
