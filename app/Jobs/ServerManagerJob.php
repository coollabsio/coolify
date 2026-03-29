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

        // Dispatch ServerConnectionCheck for all servers efficiently
        $this->dispatchConnectionChecks($servers);

        // Process server-specific scheduled tasks
        $this->processScheduledTasks($servers);
    }

    private function getServers(): Collection
    {
        $allServers = Server::with('settings')->where('ip', '!=', '1.2.3.4');

        if (isCloud()) {
            $servers = $allServers->whereRelation('team.subscription', 'stripe_invoice_paid', true)->get();
            $own = Team::find(0)->servers()->with('settings')->get();

            return $servers->merge($own);
        } else {
            return $allServers->get();
        }
    }

    private function dispatchConnectionChecks(Collection $servers): void
    {

        if (shouldRunCronNow($this->checkFrequency, $this->instanceTimezone, 'server-connection-checks', $this->executionTime)) {
            $servers->each(function (Server $server) {
                try {
                    // Skip SSH connection check if Sentinel is healthy — its heartbeat already proves connectivity
                    if ($server->isSentinelEnabled() && $server->isSentinelLive()) {
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

        if ($sentinelOutOfSync) {
            // Dispatch ServerCheckJob if Sentinel is out of sync
            if (shouldRunCronNow($this->checkFrequency, $serverTimezone, "server-check:{$server->id}", $this->executionTime)) {
                ServerCheckJob::dispatch($server);
            }
        }

        $isSentinelEnabled = $server->isSentinelEnabled();
        $shouldRestartSentinel = $isSentinelEnabled && shouldRunCronNow('0 0 * * *', $serverTimezone, "sentinel-restart:{$server->id}", $this->executionTime);
        // Dispatch Sentinel restart if due (daily for Sentinel-enabled servers)

        if ($shouldRestartSentinel) {
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

        // Dispatch ServerPatchCheckJob if due (weekly)
        $shouldRunPatchCheck = shouldRunCronNow('0 0 * * 0', $serverTimezone, "server-patch-check:{$server->id}", $this->executionTime);

        if ($shouldRunPatchCheck) { // Weekly on Sunday at midnight
            ServerPatchCheckJob::dispatch($server);
        }

        // Note: CheckAndStartSentinelJob is only dispatched daily (line above) for version updates.
        // Crash recovery is handled by sentinelOutOfSync → ServerCheckJob → CheckAndStartSentinelJob.
    }
}
