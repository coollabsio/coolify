<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDatabase extends Command
{
    protected $signature = 'cleanup:database {--yes} {--keep-days=}';

    protected $description = 'Cleanup database';

    private const DELETE_BATCH_SIZE = 1000;

    public function handle()
    {
        if ($this->option('yes')) {
            $this->line('Running database cleanup...');
        } else {
            $this->line('Running database cleanup in dry-run mode...');
        }
        if (isCloud()) {
            // Later on we can increase this to 180 days or dynamically set
            $keep_days = $this->option('keep-days') ?? 60;
        } else {
            $keep_days = $this->option('keep-days') ?? 60;
        }
        $this->line("Keep days: $keep_days");
        $this->cleanupTable('failed_jobs', 'failed_at', now()->subDays(1));

        $this->cleanupTable('sessions', 'last_activity', now()->subDays($keep_days)->timestamp);

        $this->cleanupTable('activity_log', 'created_at', now()->subDays($keep_days));

        $count = DB::table('audit_events')->where('created_at', '<', now()->subDays(90))->count();
        $this->line("Delete $count entries from audit_events.");
        if ($this->option('yes')) {
            AuditEvent::pruneExpired();
        }

        $this->cleanupTable('application_deployment_queues', 'created_at', now()->subDays($keep_days));

        $this->cleanupTable('scheduled_task_executions', 'created_at', now()->subDays($keep_days));
    }

    /**
     * Delete the rows older than the given value in batches, so a large table is not locked
     * by one long DELETE.
     */
    private function cleanupTable(string $table, string $column, mixed $olderThan): void
    {
        $count = DB::table($table)->where($column, '<', $olderThan)->count();
        $this->line("Delete $count entries from $table.");
        if (! $this->option('yes')) {
            return;
        }

        do {
            $deleted = DB::table($table)->where($column, '<', $olderThan)->limit(self::DELETE_BATCH_SIZE)->delete();
        } while ($deleted > 0);
    }
}
