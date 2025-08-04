<?php

namespace App\Console\Commands;

use App\Jobs\DatabaseBackupJob;
use App\Jobs\ScheduledTaskJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunScheduledJobsManually extends Command
{
    protected $signature = 'schedule:run-manual 
                           {--type=all : Type of jobs to run (all, backups, tasks)}
                           {--frequency= : Filter by frequency (daily, hourly, weekly, monthly, yearly, or cron expression)}
                           {--chunk=5 : Number of jobs to process in each batch}
                           {--delay=30 : Delay in seconds between batches}
                           {--max= : Maximum number of jobs to process (useful for testing)}
                           {--dry-run : Show what would be executed without actually running jobs}';

    protected $description = 'Manually run scheduled database backups and tasks when cron fails';

    public function handle()
    {
        $type = $this->option('type');
        $frequency = $this->option('frequency');
        $chunkSize = (int) $this->option('chunk');
        $delay = (int) $this->option('delay');
        $maxJobs = $this->option('max') ? (int) $this->option('max') : null;
        $dryRun = $this->option('dry-run');

        $this->info('Starting manual execution of scheduled jobs...'.($dryRun ? ' (DRY RUN)' : ''));
        $this->info("Type: {$type}".($frequency ? ", Frequency: {$frequency}" : '').", Chunk size: {$chunkSize}, Delay: {$delay}s".($maxJobs ? ", Max jobs: {$maxJobs}" : '').($dryRun ? ', Dry run: enabled' : ''));

        if ($dryRun) {
            $this->warn('DRY RUN MODE: No jobs will actually be dispatched');
        }

        if ($type === 'all' || $type === 'backups') {
            $this->runScheduledBackups($chunkSize, $delay, $maxJobs, $dryRun, $frequency);
        }

        if ($type === 'all' || $type === 'tasks') {
            $this->runScheduledTasks($chunkSize, $delay, $maxJobs, $dryRun, $frequency);
        }

        $this->info('Completed manual execution of scheduled jobs.'.($dryRun ? ' (DRY RUN)' : ''));
    }

    private function runScheduledBackups(int $chunkSize, int $delay, ?int $maxJobs = null, bool $dryRun = false, ?string $frequency = null): void
    {
        $this->info('Processing scheduled database backups...');

        $query = ScheduledDatabaseBackup::where('enabled', true);

        if ($frequency) {
            $query->where(function ($q) use ($frequency) {
                // Handle human-readable frequency strings
                if (in_array($frequency, ['daily', 'hourly', 'weekly', 'monthly', 'yearly', 'every_minute'])) {
                    $q->where('frequency', $frequency);
                } else {
                    // Handle cron expressions
                    $q->where('frequency', $frequency);
                }
            });
        }

        $scheduled_backups = $query->get();

        if ($scheduled_backups->isEmpty()) {
            $this->info('No enabled scheduled backups found'.($frequency ? " with frequency '{$frequency}'" : '').'.');

            return;
        }

        $finalScheduledBackups = collect();

        foreach ($scheduled_backups as $scheduled_backup) {
            if (blank(data_get($scheduled_backup, 'database'))) {
                $this->warn("Deleting backup {$scheduled_backup->id} - missing database");
                $scheduled_backup->delete();

                continue;
            }

            $server = $scheduled_backup->server();
            if (blank($server)) {
                $this->warn("Deleting backup {$scheduled_backup->id} - missing server");
                $scheduled_backup->delete();

                continue;
            }

            if ($server->isFunctional() === false) {
                $this->warn("Skipping backup {$scheduled_backup->id} - server not functional");

                continue;
            }

            if (isCloud() && data_get($server->team->subscription, 'stripe_invoice_paid', false) === false && $server->team->id !== 0) {
                $this->warn("Skipping backup {$scheduled_backup->id} - subscription not paid");

                continue;
            }

            $finalScheduledBackups->push($scheduled_backup);
        }

        if ($maxJobs && $finalScheduledBackups->count() > $maxJobs) {
            $finalScheduledBackups = $finalScheduledBackups->take($maxJobs);
            $this->info("Limited to {$maxJobs} scheduled backups for testing");
        }

        $this->info("Found {$finalScheduledBackups->count()} valid scheduled backups to process".($frequency ? " with frequency '{$frequency}'" : ''));

        $chunks = $finalScheduledBackups->chunk($chunkSize);
        foreach ($chunks as $index => $chunk) {
            $this->info('Processing backup batch '.($index + 1).' of '.$chunks->count()." ({$chunk->count()} items)");

            foreach ($chunk as $scheduled_backup) {
                try {
                    if ($dryRun) {
                        $this->info("🔍 Would dispatch backup job for: {$scheduled_backup->name} (ID: {$scheduled_backup->id}, Frequency: {$scheduled_backup->frequency})");
                    } else {
                        DatabaseBackupJob::dispatch($scheduled_backup);
                        $this->info("✓ Dispatched backup job for: {$scheduled_backup->name} (ID: {$scheduled_backup->id}, Frequency: {$scheduled_backup->frequency})");
                    }
                } catch (\Exception $e) {
                    $this->error("✗ Failed to dispatch backup job for {$scheduled_backup->id}: ".$e->getMessage());
                    Log::error('Error dispatching backup job: '.$e->getMessage());
                }
            }

            if ($index < $chunks->count() - 1 && ! $dryRun) {
                $this->info("Waiting {$delay} seconds before next batch...");
                sleep($delay);
            }
        }
    }

    private function runScheduledTasks(int $chunkSize, int $delay, ?int $maxJobs = null, bool $dryRun = false, ?string $frequency = null): void
    {
        $this->info('Processing scheduled tasks...');

        $query = ScheduledTask::where('enabled', true);

        if ($frequency) {
            $query->where(function ($q) use ($frequency) {
                // Handle human-readable frequency strings
                if (in_array($frequency, ['daily', 'hourly', 'weekly', 'monthly', 'yearly', 'every_minute'])) {
                    $q->where('frequency', $frequency);
                } else {
                    // Handle cron expressions
                    $q->where('frequency', $frequency);
                }
            });
        }

        $scheduled_tasks = $query->get();

        if ($scheduled_tasks->isEmpty()) {
            $this->info('No enabled scheduled tasks found'.($frequency ? " with frequency '{$frequency}'" : '').'.');

            return;
        }

        $finalScheduledTasks = collect();

        foreach ($scheduled_tasks as $scheduled_task) {
            $service = $scheduled_task->service;
            $application = $scheduled_task->application;

            $server = $scheduled_task->server();
            if (blank($server)) {
                $this->warn("Deleting task {$scheduled_task->id} - missing server");
                $scheduled_task->delete();

                continue;
            }

            if ($server->isFunctional() === false) {
                $this->warn("Skipping task {$scheduled_task->id} - server not functional");

                continue;
            }

            if (isCloud() && data_get($server->team->subscription, 'stripe_invoice_paid', false) === false && $server->team->id !== 0) {
                $this->warn("Skipping task {$scheduled_task->id} - subscription not paid");

                continue;
            }

            if (! $service && ! $application) {
                $this->warn("Deleting task {$scheduled_task->id} - missing service and application");
                $scheduled_task->delete();

                continue;
            }

            if ($application && str($application->status)->contains('running') === false) {
                $this->warn("Skipping task {$scheduled_task->id} - application not running");

                continue;
            }

            if ($service && str($service->status)->contains('running') === false) {
                $this->warn("Skipping task {$scheduled_task->id} - service not running");

                continue;
            }

            $finalScheduledTasks->push($scheduled_task);
        }

        if ($maxJobs && $finalScheduledTasks->count() > $maxJobs) {
            $finalScheduledTasks = $finalScheduledTasks->take($maxJobs);
            $this->info("Limited to {$maxJobs} scheduled tasks for testing");
        }

        $this->info("Found {$finalScheduledTasks->count()} valid scheduled tasks to process".($frequency ? " with frequency '{$frequency}'" : ''));

        $chunks = $finalScheduledTasks->chunk($chunkSize);
        foreach ($chunks as $index => $chunk) {
            $this->info('Processing task batch '.($index + 1).' of '.$chunks->count()." ({$chunk->count()} items)");

            foreach ($chunk as $scheduled_task) {
                try {
                    if ($dryRun) {
                        $this->info("🔍 Would dispatch task job for: {$scheduled_task->name} (ID: {$scheduled_task->id}, Frequency: {$scheduled_task->frequency})");
                    } else {
                        ScheduledTaskJob::dispatch($scheduled_task);
                        $this->info("✓ Dispatched task job for: {$scheduled_task->name} (ID: {$scheduled_task->id}, Frequency: {$scheduled_task->frequency})");
                    }
                } catch (\Exception $e) {
                    $this->error("✗ Failed to dispatch task job for {$scheduled_task->id}: ".$e->getMessage());
                    Log::error('Error dispatching task job: '.$e->getMessage());
                }
            }

            if ($index < $chunks->count() - 1 && ! $dryRun) {
                $this->info("Waiting {$delay} seconds before next batch...");
                sleep($delay);
            }
        }
    }
}
