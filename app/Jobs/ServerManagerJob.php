<?php

namespace App\Jobs;

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use Cron\CronExpression;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ServerManagerJob implements ShouldQueue
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
        $allServers = Server::where('ip', '!=', '1.2.3.4');

        if (isCloud()) {
            $servers = $allServers->whereRelation('team.subscription', 'stripe_invoice_paid', true)->get();
            $own = Team::find(0)->servers;

            return $servers->merge($own);
        } else {
            return $allServers->get();
        }
    }

    private function dispatchConnectionChecks(Collection $servers): void
    {

        if ($this->shouldRunNow($this->checkFrequency)) {
            $servers->each(function (Server $server) {
                try {
                    ServerConnectionCheckJob::dispatch($server);
                } catch (\Exception $e) {
                    Log::channel('scheduled-errors')->error('Failed to dispatch ServerConnectionCheck', [
                        'server_id' => $server->id,
                        'server_name' => $server->name,
                        'error' => $e->getMessage(),
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
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function processServerTasks(Server $server): void
    {
        // Check if we should run sentinel-based checks
        $lastSentinelUpdate = $server->sentinel_updated_at;
        $waitTime = $server->waitBeforeDoingSshCheck();
        $sentinelOutOfSync = Carbon::parse($lastSentinelUpdate)->isBefore($this->executionTime->subSeconds($waitTime));

        if ($sentinelOutOfSync) {
            // Dispatch jobs if Sentinel is out of sync
            if ($this->shouldRunNow($this->checkFrequency)) {
                ServerCheckJob::dispatch($server);
            }

            // Dispatch ServerStorageCheckJob if due
            $serverDiskUsageCheckFrequency = data_get($server->settings, 'server_disk_usage_check_frequency', '0 * * * *');
            if (isset(VALID_CRON_STRINGS[$serverDiskUsageCheckFrequency])) {
                $serverDiskUsageCheckFrequency = VALID_CRON_STRINGS[$serverDiskUsageCheckFrequency];
            }
            $shouldRunStorageCheck = $this->shouldRunNow($serverDiskUsageCheckFrequency);

            if ($shouldRunStorageCheck) {
                ServerStorageCheckJob::dispatch($server);
            }
        }

        $serverTimezone = data_get($server->settings, 'server_timezone', $this->instanceTimezone);
        if (validate_timezone($serverTimezone) === false) {
            $serverTimezone = config('app.timezone');
        }

        // Dispatch ServerPatchCheckJob if due (weekly)
        $shouldRunPatchCheck = $this->shouldRunNow('0 0 * * 0', $serverTimezone);

        if ($shouldRunPatchCheck) { // Weekly on Sunday at midnight
            ServerPatchCheckJob::dispatch($server);
        }

        // Dispatch Sentinel restart if due (daily for Sentinel-enabled servers)
        $isSentinelEnabled = $server->isSentinelEnabled();
        $shouldRestartSentinel = $isSentinelEnabled && $this->shouldRunNow('0 0 * * *', $serverTimezone);

        if ($shouldRestartSentinel) {
            dispatch(function () use ($server) {
                $server->restartContainer('coolify-sentinel');
            });
        }
    }

    private function shouldRunNow(string $frequency, ?string $timezone = null): bool
    {
        $cron = new CronExpression($frequency);

        // Use the frozen execution time, not the current time
        $baseTime = $this->executionTime ?? Carbon::now();
        $executionTime = $baseTime->copy()->setTimezone($timezone ?? config('app.timezone'));

        return $cron->isDue($executionTime);
    }
}
