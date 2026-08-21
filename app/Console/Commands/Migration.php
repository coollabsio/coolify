<?php

namespace App\Console\Commands;

use App\Jobs\DatabaseBackupJob;
use App\Models\StandalonePostgresql;
use App\Services\MigrationFailure;
use Illuminate\Console\Command;

class Migration extends Command
{
    protected $signature = 'start:migration';

    protected $description = 'Start Migration';

    public function handle()
    {
        if (! config('constants.migration.is_migration_enabled')) {
            $this->info('Migration is disabled on this server.');
            // Migrations are not managed here, so drop any stale failure marker
            // to avoid surfacing a permanent, unresolvable error in the UI.
            MigrationFailure::clear();
            exit(0);
        }

        $this->info('Migration is enabled on this server.');

        $this->backupInstanceDatabase();

        try {
            $exitCode = $this->call('migrate', ['--force' => true, '--isolated' => true]);
        } catch (\Throwable $e) {
            MigrationFailure::record($e->getMessage());
            $this->error('Migration failed: '.$e->getMessage());
            exit(1);
        }

        if ($exitCode !== 0) {
            MigrationFailure::record("Migration command exited with code {$exitCode}.");
            exit($exitCode);
        }

        MigrationFailure::clear();
        exit(0);
    }

    /**
     * Take a best-effort backup of Coolify's own database before running migrations,
     * so a failed migration is recoverable. A backup failure is logged and ignored so
     * it never aborts the upgrade; a successful backup does add to the upgrade time,
     * and it is skipped automatically if another coolify-db backup is already running.
     */
    private function backupInstanceDatabase(): void
    {
        if (! config('constants.migration.backup_before_migration')) {
            return;
        }

        try {
            $backup = StandalonePostgresql::whereName('coolify-db')->first()?->scheduledBackups()->first();
            if (! $backup) {
                $this->warn('No coolify-db backup configuration found; skipping pre-migration backup.');

                return;
            }

            $this->info('Taking a database backup before running migrations...');
            DatabaseBackupJob::dispatchSync($backup);
            $this->info('Pre-migration database backup finished (skipped automatically if one was already in progress).');
        } catch (\Throwable $e) {
            $this->warn('Pre-migration database backup failed (continuing with migration): '.$e->getMessage());
        }
    }
}
