<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class Migration extends Command
{
    protected $signature = 'start:migration';

    protected $description = 'Start Migration';

    private const DEADLOCK_ATTEMPTS = 3;

    public function handle()
    {
        if (! config('constants.migration.is_migration_enabled')) {
            $this->info('Migration is disabled on this server.');
            exit(0);
        }

        $this->info('Migration is enabled on this server.');

        for ($attempt = 1; ; $attempt++) {
            try {
                $this->call('migrate', ['--force' => true, '--isolated' => true]);
                break;
            } catch (QueryException $e) {
                // Postgres rolls back the migration of a deadlock (40P01), so the next attempt starts it again.
                if ($e->getCode() !== '40P01' || $attempt >= self::DEADLOCK_ATTEMPTS) {
                    throw $e;
                }
                $this->warn("Migration deadlocked with another database session, retrying ({$attempt}/".self::DEADLOCK_ATTEMPTS.').');
                sleep(5 * $attempt);
            }
        }

        exit(0);
    }
}
