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
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DEPRECATEDServerResourceManager implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The time when this job execution started.
     */
    private ?Carbon $executionTime = null;

    private InstanceSettings $settings;

    private string $instanceTimezone;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('high');
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('server-resource-manager'))
                ->releaseAfter(60),
        ];
    }

    public function handle(): void
    {
        // Freeze the execution time at the start of the job
        $this->executionTime = Carbon::now();

        $this->settings = instanceSettings();
        $this->instanceTimezone = $this->settings->instance_timezone ?: config('app.timezone');

        if (validate_timezone($this->instanceTimezone) === false) {
            $this->instanceTimezone = config('app.timezone');
        }

        // Process server checks - don't let failures stop the job
        try {
            $this->processServerChecks();
        } catch (\Exception $e) {
            Log::channel('scheduled-errors')->error('Failed to process server checks', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function processServerChecks(): void
    {
        $servers = $this->getServers();

        foreach ($servers as $server) {
            try {
                $this->processServer($server);
            } catch (\Exception $e) {
                Log::channel('scheduled-errors')->error('Error processing server', [
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function getServers()
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

    private function processServer(Server $server): void
    {
        $serverTimezone = data_get($server->settings, 'server_timezone', $this->instanceTimezone);
        if (validate_timezone($serverTimezone) === false) {
            $serverTimezone = config('app.timezone');
        }

        // Sentinel check
        $lastSentinelUpdate = $server->sentinel_updated_at;
        if (Carbon::parse($lastSentinelUpdate)->isBefore($this->executionTime->subSeconds($server->waitBeforeDoingSshCheck()))) {
            // Dispatch ServerCheckJob if due
            $checkFrequency = isCloud() ? '*/5 * * * *' : '* * * * *'; // Every 5 min for cloud, every minute for self-hosted
            if ($this->shouldRunNow($checkFrequency, $serverTimezone)) {
                ServerCheckJob::dispatch($server);
            }

            // Dispatch ServerStorageCheckJob if due
            $serverDiskUsageCheckFrequency = data_get($server->settings, 'server_disk_usage_check_frequency', '0 * * * *');
            if (isset(VALID_CRON_STRINGS[$serverDiskUsageCheckFrequency])) {
                $serverDiskUsageCheckFrequency = VALID_CRON_STRINGS[$serverDiskUsageCheckFrequency];
            }
            if ($this->shouldRunNow($serverDiskUsageCheckFrequency, $serverTimezone)) {
                ServerStorageCheckJob::dispatch($server);
            }
        }

        // Dispatch DockerCleanupJob if due
        $dockerCleanupFrequency = data_get($server->settings, 'docker_cleanup_frequency', '0 * * * *');
        if (isset(VALID_CRON_STRINGS[$dockerCleanupFrequency])) {
            $dockerCleanupFrequency = VALID_CRON_STRINGS[$dockerCleanupFrequency];
        }
        if ($this->shouldRunNow($dockerCleanupFrequency, $serverTimezone)) {
            DockerCleanupJob::dispatch($server, false, $server->settings->delete_unused_volumes, $server->settings->delete_unused_networks);
        }

        // Dispatch ServerPatchCheckJob if due (weekly)
        if ($this->shouldRunNow('0 0 * * 0', $serverTimezone)) { // Weekly on Sunday at midnight
            ServerPatchCheckJob::dispatch($server);
        }

        // Dispatch Sentinel restart if due (daily for Sentinel-enabled servers)
        if ($server->isSentinelEnabled() && $this->shouldRunNow('0 0 * * *', $serverTimezone)) {
            dispatch(function () use ($server) {
                $server->restartContainer('coolify-sentinel');
            });
        }
    }

    private function shouldRunNow(string $frequency, string $timezone): bool
    {
        $cron = new CronExpression($frequency);

        // Use the frozen execution time, not the current time
        $baseTime = $this->executionTime ?? Carbon::now();
        $executionTime = $baseTime->copy()->setTimezone($timezone);

        return $cron->isDue($executionTime);
    }
}
