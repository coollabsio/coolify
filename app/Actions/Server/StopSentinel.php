<?php

namespace App\Actions\Server;

use App\Enums\SentinelRuntime;
use App\Models\Server;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Removes Sentinel from the server in both runtimes. StartSentinel replaces a running Sentinel itself and does not call this.
 */
class StopSentinel
{
    use AsAction;

    public function handle(Server $server): void
    {
        // A start that runs now would install Sentinel again after the removal.
        try {
            Cache::lock(StartSentinel::lockKey($server), StartSentinel::LOCK_SECONDS)->block(
                StartSentinel::LOCK_WAIT_SECONDS,
                fn () => $this->stop($server),
            );
        } catch (LockTimeoutException) {
            $this->stop($server);
        }
    }

    private function stop(Server $server): void
    {
        $server->refresh();
        instant_remote_process(['docker rm -f coolify-sentinel'], $server, throwError: false);
        // The runtime stays as it is, so the next start also removes a service that this removal missed.
        if (StartSentinel::actualRuntime($server) === SentinelRuntime::Host) {
            RemoveHostSentinel::run($server);
        }
        $server->sentinelHeartbeat(isReset: true);
    }
}
