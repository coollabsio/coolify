<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDatabase extends Command
{
    protected $signature = 'cleanup:database {--yes} {--keep-days=}';

    protected $description = 'Cleanup database';

    public function handle()
    {
        if ($this->option('yes')) {
            echo "Running database cleanup...\n";
        } else {
            echo "Running database cleanup in dry-run mode...\n";
        }
        if (isCloud()) {
            // Later on we can increase this to 180 days or dynamically set
            $keep_days = $this->option('keep-days') ?? 60;
        } else {
            $keep_days = $this->option('keep-days') ?? 60;
        }
        echo "Keep days: $keep_days\n";
        // Cleanup failed jobs table
        $failed_jobs = DB::table('failed_jobs')->where('failed_at', '<', now()->subDays(1));
        $count = $failed_jobs->count();
        echo "Delete $count entries from failed_jobs.\n";
        if ($this->option('yes')) {
            $failed_jobs->delete();
        }

        // Cleanup sessions table
        $sessions = DB::table('sessions')->where('last_activity', '<', now()->subDays($keep_days)->timestamp);
        $count = $sessions->count();
        echo "Delete $count entries from sessions.\n";
        if ($this->option('yes')) {
            $sessions->delete();
        }

        // Cleanup activity_log table
        $activity_log = DB::table('activity_log')->where('created_at', '<', now()->subDays($keep_days))->orderBy('created_at', 'desc')->skip(10);
        $count = $activity_log->count();
        echo "Delete $count entries from activity_log.\n";
        if ($this->option('yes')) {
            $activity_log->delete();
        }

        // Cleanup application_deployment_queues table
        $application_deployment_queues = DB::table('application_deployment_queues')->where('created_at', '<', now()->subDays($keep_days))->orderBy('created_at', 'desc')->skip(10);
        $count = $application_deployment_queues->count();
        echo "Delete $count entries from application_deployment_queues.\n";
        if ($this->option('yes')) {
            $application_deployment_queues->delete();
        }

        // Cleanup scheduled_task_executions table
        $scheduled_task_executions = DB::table('scheduled_task_executions')->where('created_at', '<', now()->subDays($keep_days))->orderBy('created_at', 'desc');
        $count = $scheduled_task_executions->count();
        echo "Delete $count entries from scheduled_task_executions.\n";
        if ($this->option('yes')) {
            $scheduled_task_executions->delete();
        }

        // Cleanup webhooks table
        $webhooks = DB::table('webhooks')->where('created_at', '<', now()->subDays($keep_days));
        $count = $webhooks->count();
        echo "Delete $count entries from webhooks.\n";
        if ($this->option('yes')) {
            $webhooks->delete();
        }
    }
}
