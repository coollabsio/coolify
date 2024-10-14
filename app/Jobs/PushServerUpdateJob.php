<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class PushServerUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 60;

    public function backoff(): int
    {
        return isDev() ? 1 : 3;
    }

    public function __construct(public Server $server, public $data) {}

    public function handle()
    {
        if (!$this->data) {
            throw new \Exception('No data provided');
        }
        $data = collect($this->data);
        $containers = collect(data_get($data, 'containers'));
        if ($containers->isEmpty()) {
            return;
        }
        foreach ($containers as $container) {
            $containerStatus = data_get($container, 'status', 'exited');
            $containerHealth = data_get($container, 'health', 'unhealthy');
            $containerStatus = "$containerStatus ($containerHealth)";
            $labels = collect(data_get($container, 'labels'));
            if ($labels->has('coolify.applicationId')) {
                $applicationId = $labels->get('coolify.applicationId');
            }
            Log::info("$applicationId, $containerStatus");
        }
    }


}
