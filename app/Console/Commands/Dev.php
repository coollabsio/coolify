<?php

namespace App\Console\Commands;

use App\Jobs\CheckHelperImageJob;
use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTaskExecution;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class Dev extends Command
{
    protected $signature = 'dev {--init}';

    protected $description = 'Helper commands for development.';

    public function handle()
    {
        if ($this->option('init')) {
            $this->init();

            return;
        }
    }

    public function init()
    {
        // Generate APP_KEY if not exists

        if (empty(config('app.key'))) {
            echo "Generating APP_KEY.\n";
            Artisan::call('key:generate');
        }

        // Generate STORAGE link if not exists
        if (! file_exists(public_path('storage'))) {
            echo "Generating STORAGE link.\n";
            Artisan::call('storage:link');
        }

        // Seed database if it's empty
        $settings = InstanceSettings::find(0);
        if (! $settings) {
            echo "Initializing instance, seeding database.\n";
            Artisan::call('migrate --seed');
        } else {
            echo "Instance already initialized.\n";
        }

        // Clean up stuck jobs and stale locks on development startup
        try {
            echo "Cleaning up Redis (stuck jobs and stale locks)...\n";
            Artisan::call('cleanup:redis', ['--restart' => true, '--clear-locks' => true]);
            echo "Redis cleanup completed.\n";
        } catch (\Throwable $e) {
            echo "Error in cleanup:redis: {$e->getMessage()}\n";
        }

        try {
            $updatedTaskCount = ScheduledTaskExecution::where('status', 'running')->update([
                'status' => 'failed',
                'message' => 'Marked as failed during Coolify startup - job was interrupted',
                'finished_at' => Carbon::now(),
            ]);

            if ($updatedTaskCount > 0) {
                echo "Marked {$updatedTaskCount} stuck scheduled task executions as failed\n";
            }
        } catch (\Throwable $e) {
            echo "Could not cleanup stuck scheduled task executions: {$e->getMessage()}\n";
        }

        try {
            $updatedBackupCount = ScheduledDatabaseBackupExecution::where('status', 'running')->update([
                'status' => 'failed',
                'message' => 'Marked as failed during Coolify startup - job was interrupted',
                'finished_at' => Carbon::now(),
            ]);

            if ($updatedBackupCount > 0) {
                echo "Marked {$updatedBackupCount} stuck database backup executions as failed\n";
            }
        } catch (\Throwable $e) {
            echo "Could not cleanup stuck database backup executions: {$e->getMessage()}\n";
        }

        CheckHelperImageJob::dispatch();
    }
}
