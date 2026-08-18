<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RemoveContainerJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public int $serverId, public string $containerName) {}

    public function handle(): void
    {
        $server = Server::findOrFail($this->serverId);

        instant_remote_process(
            [dockerRemoveCommandWithTimeout($this->containerName)],
            $server,
            timeout: 75,
            disableMultiplexing: true,
        );
    }

    public function backoff(): array
    {
        return [300, 900];
    }

    public function failed(?\Throwable $exception): void
    {
        Log::warning('Deferred container removal failed', [
            'server_id' => $this->serverId,
            'container' => $this->containerName,
            'error' => $exception?->getMessage(),
        ]);
    }
}
