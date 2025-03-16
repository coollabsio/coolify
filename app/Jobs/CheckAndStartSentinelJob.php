<?php

namespace App\Jobs;

use App\Actions\Server\StartSentinel;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckAndStartSentinelJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public function __construct(public Server $server) {}

    public function handle(): void
    {
        $latestVersion = get_latest_sentinel_version();

        // Check if sentinel is running
        $sentinelFound = instant_remote_process_with_timeout(['docker inspect coolify-sentinel'], $this->server, false, 10);
        $sentinelFoundJson = json_decode($sentinelFound, true);
        $sentinelStatus = data_get($sentinelFoundJson, '0.State.Status', 'exited');
        if ($sentinelStatus !== 'running') {
            StartSentinel::run(server: $this->server, restart: true, latestVersion: $latestVersion);

            return;
        }
        // If sentinel is running, check if it needs an update
        $runningVersion = instant_remote_process_with_timeout(['docker exec coolify-sentinel sh -c "curl http://127.0.0.1:8888/api/version"'], $this->server, false);
        if (empty($runningVersion)) {
            $runningVersion = '0.0.0';
        }
        if ($latestVersion === '0.0.0' && $runningVersion === '0.0.0') {
            StartSentinel::run(server: $this->server, restart: true, latestVersion: 'latest');

            return;
        } else {
            if (version_compare($runningVersion, $latestVersion, '<')) {
                StartSentinel::run(server: $this->server, restart: true, latestVersion: $latestVersion);

                return;
            }
        }
    }
}
