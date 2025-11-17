<?php

namespace App\Jobs;

use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckTraefikVersionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function handle(): void
    {
        // Load versions from cached data
        $traefikVersions = get_traefik_versions();

        if (empty($traefikVersions)) {
            return;
        }

        // Query all servers with Traefik proxy that are reachable
        $servers = Server::whereNotNull('proxy')
            ->whereProxyType(ProxyTypes::TRAEFIK->value)
            ->whereRelation('settings', 'is_reachable', true)
            ->whereRelation('settings', 'is_usable', true)
            ->get();

        $serverCount = $servers->count();

        if ($serverCount === 0) {
            return;
        }

        // Dispatch individual server check jobs in parallel
        foreach ($servers as $server) {
            CheckTraefikVersionForServerJob::dispatch($server, $traefikVersions);
        }

        // Dispatch notification job with delay to allow server checks to complete
        // Jobs run in parallel via queue workers, but we need to account for:
        // - Queue worker capacity (workers process jobs concurrently)
        // - Job timeout (60s per server check)
        // - Retry attempts (3 retries with exponential backoff)
        // - Network latency and SSH connection overhead
        //
        // Calculation strategy:
        // - Assume ~10-20 workers processing the high queue
        // - Each server check takes up to 60s (timeout)
        // - With retries, worst case is ~180s per job
        // - More conservative: 0.2s per server (instead of 0.1s)
        // - Higher minimum: 120s (instead of 60s) to account for retries
        // - Keep 300s maximum to avoid excessive delays
        $delaySeconds = $this->calculateNotificationDelay($serverCount);
        if (isDev()) {
            $delaySeconds = 1;
        }
        NotifyOutdatedTraefikServersJob::dispatch()->delay(now()->addSeconds($delaySeconds));
    }

    /**
     * Calculate the delay in seconds before sending notifications.
     *
     * This method calculates an appropriate delay to allow all parallel
     * CheckTraefikVersionForServerJob instances to complete before sending
     * notifications to teams.
     *
     * The calculation considers:
     * - Server count (more servers = longer delay)
     * - Queue worker capacity
     * - Job timeout (60s) and retry attempts (3x)
     * - Network latency and SSH connection overhead
     *
     * @param  int  $serverCount  Number of servers being checked
     * @return int Delay in seconds
     */
    protected function calculateNotificationDelay(int $serverCount): int
    {
        $minDelay = config('constants.server_checks.notification_delay_min');
        $maxDelay = config('constants.server_checks.notification_delay_max');
        $scalingFactor = config('constants.server_checks.notification_delay_scaling');

        // Calculate delay based on server count
        // More conservative approach: 0.2s per server
        $calculatedDelay = (int) ($serverCount * $scalingFactor);

        // Apply min/max boundaries
        return min($maxDelay, max($minDelay, $calculatedDelay));
    }
}
