<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class Migration extends Command
{
    protected $signature = 'start:migration {--step}';

    protected $description = 'Start Migration';

    public function handle(): int
    {
        if (config('constants.migration.is_migration_enabled')) {
            $this->info('Migration is enabled on this server.');
            try {
                $this->call('migrate', ['--force' => true, '--step' => $this->option('step')]);
            } catch (Throwable $e) {
                File::put(storage_path('app/migration-failed.json'), json_encode([
                    'version' => config('constants.coolify.version'),
                    // The driver's message has no SQL or bindings, unlike the QueryException that wraps it.
                    'error' => Str::before($e->getPrevious()?->getMessage() ?? $e->getMessage(), "\n"),
                    'failed_at' => now()->toIso8601String(),
                ], JSON_PRETTY_PRINT));
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        } else {
            $this->info('Migration is disabled on this server.');
        }
        File::delete(storage_path('app/migration-failed.json'));

        return self::SUCCESS;
    }
}
