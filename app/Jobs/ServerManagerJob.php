<?php

namespace App\Jobs;

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ServerManagerJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The time when this job execution started.
     */
    private ?Carbon $executionTime = null;

    private InstanceSettings $settings;

    private string $instanceTimezone;

    private string $checkFrequency = '* * * * *';

    /**
     * Provider state seldom changes, and each check is one API call per server.
     * Hetzner allows 3600 requests per hour for each token.
     */
    private const CLOUD_PROVIDER_STATUS_CHECK_CRON = '*/5 * * * *';

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        // Freeze the execution time at the start of the job
        $this->executionTime = Carbon::now();
        if (isCloud()) {
            $this->checkFrequency = '*/5 * * * *';
        }
        $this->settings = instanceSettings();
        $this->instanceTimezone = $this->settings->instance_timezone ?: config('app.timezone');

        if (validate_timezone($this->instanceTimezone) === false) {
            $this->instanceTimezone = config('app.timezone');
        }

        // Get all servers to process
        $servers = $this->getServers();

        // Provider state checks run independently so slow APIs cannot block SSH checks.
        $this->dispatchCloudProviderStatusChecks($servers);

        $servers = $servers
            ->reject(fn (Server $server) => $server->hasPlaceholderIp())
            ->values();

        // Dispatch ServerConnectionCheck for all servers efficiently
        $this->dispatchConnectionChecks($servers);

        // Process server-specific scheduled tasks
        $this->processScheduledTasks($servers);
    }

    private function getServers(): Collection
    {
        $allServers = Server::with(['settings', 'cloudProviderToken']);

        if (isCloud()) {
            $servers = $allServers->whereRelation('team.subscription', 'stripe_invoice_paid', true)->get();
            $own = Team::find(0)->servers()->with(['settings', 'cloudProviderToken'])->get();

            return $servers->merge($own)->unique('id')->values();
        } else {
            return $allServers->get();
        }
    }

    private function dispatchCloudProviderStatusChecks(Collection $servers): void
    {
        if (! shouldRunCronNow(self::CLOUD_PROVIDER_STATUS_CHECK_CRON, $this->instanceTimezone, 'server-cloud-provider-status-checks', $this->executionTime)) {
            return;
        }

        $servers->each(function (Server $server) {
            $hasCloudResource = $server->hetzner_server_id
                || $server->vultr_instance_id
                || $server->digitalocean_droplet_id
                || $server->hostinger_virtual_machine_id;

            if ($hasCloudResource && $server->cloudProviderToken) {
                ServerCloudProviderStatusCheckJob::dispatch($server);
            }
        });
    }

    private function dispatchConnectionChecks(Collection $servers): void
    {

        if (shouldRunCronNow($this->checkFrequency, $this->instanceTimezone, 'server-connection-checks', $this->executionTime)) {
            $servers->each(function (Server $server) {
                try {
                    if ($server->hasPlaceholderIp()) {
                        return;
                    }

                    // Skip SSH connection check if Sentinel is healthy — its heartbeat already proves connectivity.
                    // Never skip while the server is marked unreachable or unusable: the heartbeat does not
                    // restore these flags, so deployments would fail with "Server is not functional" forever.
                    if ($server->isSentinelEnabled() && $server->isSentinelLive()
                        && $server->settings->is_reachable && $server->settings->is_usable) {
                        return;
                    }
                    if ($this->shouldSkipDueToBackoff($server)) {
                        return;
                    }
                    ServerConnectionCheckJob::dispatch($server);
                } catch (\Exception $e) {
                    Log::channel('scheduled-errors')->error('Failed to dispatch ServerConnectionCheck', [
                        'server_id' => $server->id,
                        'server_name' => $server->name,
                        'error' => get_class($e).': '.$e->getMessage(),
                    ]);
                }
            });
        }
    }

    private function processScheduledTasks(Collection $servers): void
    {
        foreach ($servers as $server) {
            try {
                $this->processServerTasks($server);
            } catch (\Exception $e) {
                Log::channel('scheduled-errors')->error('Error processing server tasks', [
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                    'error' => get_class($e).': '.$e->getMessage(),
                ]);
            }
        }
    }

    private function processServerTasks(Server $server): void
    {
        // Get server timezone (used for all scheduled tasks)
        $serverTimezone = data_get($server->settings, 'server_timezone', $this->instanceTimezone);
        if (validate_timezone($serverTimezone) === false) {
            $serverTimezone = config('app.timezone');
        }

        // Check if we should run sentinel-based checks
        $lastSentinelUpdate = $server->sentinel_updated_at;
        $waitTime = $server->waitBeforeDoingSshCheck();
        $sentinelOutOfSync = Carbon::parse($lastSentinelUpdate)->isBefore($this->executionTime->copy()->subSeconds($waitTime));

        // Build servers and Swarm workers have no containers to sync, and ServerConnectionCheckJob
        // already checks their connection.
        $hasContainersToSync = ! $server->isBuildServer() && ! $server->isSwarmWorker();

        if ($sentinelOutOfSync && $hasContainersToSync) {
            // Dispatch ServerCheckJob if Sentinel is out of sync
            if (shouldRunCronNow($this->checkFrequency, $serverTimezone, "server-check:{$server->id}", $this->executionTime)) {
                if (! $this->shouldSkipDueToBackoff($server)) {
                    ServerCheckJob::dispatch($server);
                }
            }
        }

        // Sentinel versions that report their version on push are updated by SentinelController.
        // Unreachable servers are skipped: the SSH check would only fail. The connection check recovers them.
        if ($server->isSentinelEnabled()
            && ! Cache::has(Server::sentinelReportedVersionCacheKey($server->id))
            && shouldRunCronNow(self::sentinelVersionCheckCron($server), $serverTimezone, "sentinel-version-check-v2:{$server->id}", $this->executionTime)
            && $server->isFunctional()
        ) {
            CheckAndStartSentinelJob::dispatch($server);
        }

        // Dispatch ServerStorageCheckJob if due (only when Sentinel is out of sync or disabled)
        // When Sentinel is active, PushServerUpdateJob handles storage checks with real-time data
        if ($sentinelOutOfSync) {
            $serverDiskUsageCheckFrequency = data_get($server->settings, 'server_disk_usage_check_frequency', '0 23 * * *');
            if (isset(VALID_CRON_STRINGS[$serverDiskUsageCheckFrequency])) {
                $serverDiskUsageCheckFrequency = VALID_CRON_STRINGS[$serverDiskUsageCheckFrequency];
            }
            $shouldRunStorageCheck = shouldRunCronNow($serverDiskUsageCheckFrequency, $serverTimezone, "server-storage-check:{$server->id}", $this->executionTime);

            if ($shouldRunStorageCheck) {
                ServerStorageCheckJob::dispatch($server);
            }
        }

        // Dispatch ServerPatchCheckJob if due (weekly, staggered per server on Sunday).
        // The "-v2" keys start fresh: v4.3.23 stored its old run times under the unversioned keys, which
        // would make every server run these checks at once on the first run after the upgrade.
        $shouldRunPatchCheck = shouldRunCronNow(self::patchCheckCron($server), $serverTimezone, "server-patch-check-v2:{$server->id}", $this->executionTime);

        if ($shouldRunPatchCheck) {
            ServerPatchCheckJob::dispatch($server);
        }

        // Crash recovery is handled by sentinelOutOfSync → ServerCheckJob → CheckAndStartSentinelJob.
    }

    /**
     * Hourly Sentinel version check at a stable per-server minute, so servers do not all SSH at minute 0.
     *
     * ServerManagerJob evaluates this every minute and shouldRunCronNow() catches up a missed minute
     * on the next run, so every minute of the hour is a safe slot.
     */
    public static function sentinelVersionCheckCron(Server $server): string
    {
        return sprintf('%d * * * *', $server->id % 60);
    }

    /**
     * Weekly patch check at a stable per-server time on Sunday between 04:00 and 23:59.
     *
     * The early hours are skipped because most daylight saving transitions happen there.
     */
    public static function patchCheckCron(Server $server): string
    {
        $slot = $server->id % (20 * 60);

        return sprintf('%d %d * * 0', $slot % 60, 4 + intdiv($slot, 60));
    }

    /**
     * Determine the backoff cycle interval based on how many consecutive times a server has been unreachable.
     * Higher counts → less frequent checks (based on 5-min cloud cycle):
     *   0-2: every cycle, 3-5: ~15 min, 6-11: ~30 min, 12+: ~60 min
     */
    private function getBackoffCycleInterval(int $unreachableCount): int
    {
        return match (true) {
            $unreachableCount <= 2 => 1,
            $unreachableCount <= 5 => 3,
            $unreachableCount <= 11 => 6,
            default => 12,
        };
    }

    /**
     * Check if a server should be skipped this cycle due to unreachable backoff.
     * Uses server ID hash to distribute checks across cycles (avoid thundering herd).
     */
    private function shouldSkipDueToBackoff(Server $server): bool
    {
        $unreachableCount = $server->unreachable_count ?? 0;
        $interval = $this->getBackoffCycleInterval($unreachableCount);

        if ($interval <= 1) {
            return false;
        }

        $cyclePeriodMinutes = isCloud() ? 5 : 1;
        $cycleIndex = intdiv($this->executionTime->minute, $cyclePeriodMinutes);
        $serverHash = abs(crc32((string) $server->id));

        return ($cycleIndex + $serverHash) % $interval !== 0;
    }
}
