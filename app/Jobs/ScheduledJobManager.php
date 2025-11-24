<?php

namespace App\Jobs;

use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ScheduledJobManager implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The time when this job execution started.
     * Used to ensure all scheduled items are evaluated against the same point in time.
     */
    private ?Carbon $executionTime = null;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue($this->determineQueue());
    }

    private function determineQueue(): string
    {
        $preferredQueue = 'crons';
        $fallbackQueue = 'high';

        $configuredQueues = explode(',', env('HORIZON_QUEUES', 'high,default'));

        return in_array($preferredQueue, $configuredQueues) ? $preferredQueue : $fallbackQueue;
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('scheduled-job-manager'))
                ->expireAfter(90)   // Lock expires after 90s to handle high-load environments with many tasks
                ->dontRelease(),    // Don't re-queue on lock conflict
        ];
    }

    public function handle(): void
    {
        // Freeze the execution time at the start of the job
        $this->executionTime = Carbon::now();

        // Process backups - don't let failures stop task processing
        try {
            $this->processScheduledBackups();
        } catch (\Exception $e) {
            Log::channel('scheduled-errors')->error('Failed to process scheduled backups', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Process tasks - don't let failures stop the job manager
        try {
            $this->processScheduledTasks();
        } catch (\Exception $e) {
            Log::channel('scheduled-errors')->error('Failed to process scheduled tasks', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Process Docker cleanups - don't let failures stop the job manager
        try {
            $this->processDockerCleanups();
        } catch (\Exception $e) {
            Log::channel('scheduled-errors')->error('Failed to process docker cleanups', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function processScheduledBackups(): void
    {
        $backups = ScheduledDatabaseBackup::with(['database'])
            ->where('enabled', true)
            ->get();

        foreach ($backups as $backup) {
            try {
                // Apply the same filtering logic as the original
                if (! $this->shouldProcessBackup($backup)) {
                    continue;
                }

                $server = $backup->server();
                $serverTimezone = data_get($server->settings, 'server_timezone', config('app.timezone'));

                if (validate_timezone($serverTimezone) === false) {
                    $serverTimezone = config('app.timezone');
                }

                $frequency = $backup->frequency;
                if (isset(VALID_CRON_STRINGS[$frequency])) {
                    $frequency = VALID_CRON_STRINGS[$frequency];
                }

                if ($this->shouldRunNow($frequency, $serverTimezone)) {
                    DatabaseBackupJob::dispatch($backup);
                }
            } catch (\Exception $e) {
                Log::channel('scheduled-errors')->error('Error processing backup', [
                    'backup_id' => $backup->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function processScheduledTasks(): void
    {
        $tasks = ScheduledTask::with(['service', 'application'])
            ->where('enabled', true)
            ->get();

        foreach ($tasks as $task) {
            try {
                if (! $this->shouldProcessTask($task)) {
                    continue;
                }

                $server = $task->server();
                $serverTimezone = data_get($server->settings, 'server_timezone', config('app.timezone'));

                if (validate_timezone($serverTimezone) === false) {
                    $serverTimezone = config('app.timezone');
                }

                $frequency = $task->frequency;
                if (isset(VALID_CRON_STRINGS[$frequency])) {
                    $frequency = VALID_CRON_STRINGS[$frequency];
                }

                if ($this->shouldRunNow($frequency, $serverTimezone)) {
                    ScheduledTaskJob::dispatch($task);
                }
            } catch (\Exception $e) {
                Log::channel('scheduled-errors')->error('Error processing task', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function shouldProcessBackup(ScheduledDatabaseBackup $backup): bool
    {
        if (blank(data_get($backup, 'database'))) {
            $backup->delete();

            return false;
        }

        $server = $backup->server();
        if (blank($server)) {
            $backup->delete();

            return false;
        }

        if ($server->isFunctional() === false) {
            return false;
        }

        if (isCloud() && data_get($server->team->subscription, 'stripe_invoice_paid', false) === false && $server->team->id !== 0) {
            return false;
        }

        return true;
    }

    private function shouldProcessTask(ScheduledTask $task): bool
    {
        $service = $task->service;
        $application = $task->application;

        $server = $task->server();
        if (blank($server)) {
            $task->delete();

            return false;
        }

        if ($server->isFunctional() === false) {
            return false;
        }

        if (isCloud() && data_get($server->team->subscription, 'stripe_invoice_paid', false) === false && $server->team->id !== 0) {
            return false;
        }

        if (! $service && ! $application) {
            $task->delete();

            return false;
        }

        if ($application && str($application->status)->contains('running') === false) {
            return false;
        }

        if ($service && str($service->status)->contains('running') === false) {
            return false;
        }

        return true;
    }

    private function shouldRunNow(string $frequency, string $timezone): bool
    {
        $cron = new CronExpression($frequency);

        // Use the frozen execution time, not the current time
        // Fallback to current time if execution time is not set (shouldn't happen)
        $baseTime = $this->executionTime ?? Carbon::now();
        $executionTime = $baseTime->copy()->setTimezone($timezone);

        return $cron->isDue($executionTime);
    }

    private function processDockerCleanups(): void
    {
        // Get all servers that need cleanup checks
        $servers = $this->getServersForCleanup();

        foreach ($servers as $server) {
            try {
                if (! $this->shouldProcessDockerCleanup($server)) {
                    continue;
                }

                $serverTimezone = data_get($server->settings, 'server_timezone', config('app.timezone'));
                if (validate_timezone($serverTimezone) === false) {
                    $serverTimezone = config('app.timezone');
                }

                $frequency = data_get($server->settings, 'docker_cleanup_frequency', '0 * * * *');
                if (isset(VALID_CRON_STRINGS[$frequency])) {
                    $frequency = VALID_CRON_STRINGS[$frequency];
                }

                // Use the frozen execution time for consistent evaluation
                if ($this->shouldRunNow($frequency, $serverTimezone)) {
                    DockerCleanupJob::dispatch(
                        $server,
                        false,
                        $server->settings->delete_unused_volumes,
                        $server->settings->delete_unused_networks
                    );
                }
            } catch (\Exception $e) {
                Log::channel('scheduled-errors')->error('Error processing docker cleanup', [
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function getServersForCleanup(): Collection
    {
        $query = Server::with('settings')
            ->where('ip', '!=', '1.2.3.4');

        if (isCloud()) {
            $servers = $query->whereRelation('team.subscription', 'stripe_invoice_paid', true)->get();
            $own = Team::find(0)->servers()->with('settings')->get();

            return $servers->merge($own);
        }

        return $query->get();
    }

    private function shouldProcessDockerCleanup(Server $server): bool
    {
        if (! $server->isFunctional()) {
            return false;
        }

        // In cloud, check subscription status (except team 0)
        if (isCloud() && $server->team_id !== 0) {
            if (data_get($server->team->subscription, 'stripe_invoice_paid', false) === false) {
                return false;
            }
        }

        return true;
    }
}
