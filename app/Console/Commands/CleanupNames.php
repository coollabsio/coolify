<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Tag;
use App\Models\Team;
use App\Support\ValidationPatterns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupNames extends Command
{
    protected $signature = 'cleanup:names 
                            {--dry-run : Preview changes without applying them}
                            {--model= : Clean specific model (e.g., Project, Server)}
                            {--backup : Create database backup before changes}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Sanitize name fields by removing invalid characters (keeping only letters, numbers, spaces, dashes, underscores, dots, slashes, colons, parentheses)';

    protected array $modelsToClean = [
        'Project' => Project::class,
        'Environment' => Environment::class,
        'Application' => Application::class,
        'Service' => Service::class,
        'Server' => Server::class,
        'Team' => Team::class,
        'StandalonePostgresql' => StandalonePostgresql::class,
        'StandaloneMysql' => StandaloneMysql::class,
        'StandaloneRedis' => StandaloneRedis::class,
        'StandaloneMongodb' => StandaloneMongodb::class,
        'StandaloneMariadb' => StandaloneMariadb::class,
        'StandaloneKeydb' => StandaloneKeydb::class,
        'StandaloneDragonfly' => StandaloneDragonfly::class,
        'StandaloneClickhouse' => StandaloneClickhouse::class,
        'S3Storage' => S3Storage::class,
        'Tag' => Tag::class,
        'PrivateKey' => PrivateKey::class,
        'ScheduledTask' => ScheduledTask::class,
    ];

    protected array $changes = [];

    protected int $totalProcessed = 0;

    protected int $totalCleaned = 0;

    public function handle(): int
    {
        $this->info('🔍 Scanning for invalid characters in name fields...');

        if ($this->option('backup') && ! $this->option('dry-run')) {
            $this->createBackup();
        }

        $modelFilter = $this->option('model');
        $modelsToProcess = $modelFilter
            ? [$modelFilter => $this->modelsToClean[$modelFilter] ?? null]
            : $this->modelsToClean;

        if ($modelFilter && ! isset($this->modelsToClean[$modelFilter])) {
            $this->error("❌ Unknown model: {$modelFilter}");
            $this->info('Available models: '.implode(', ', array_keys($this->modelsToClean)));

            return self::FAILURE;
        }

        foreach ($modelsToProcess as $modelName => $modelClass) {
            if (! $modelClass) {
                continue;
            }
            $this->processModel($modelName, $modelClass);
        }

        $this->displaySummary();

        if (! $this->option('dry-run') && $this->totalCleaned > 0) {
            $this->logChanges();
        }

        return self::SUCCESS;
    }

    protected function processModel(string $modelName, string $modelClass): void
    {
        $this->info("\n📋 Processing {$modelName}...");

        try {
            $records = $modelClass::all(['id', 'name']);
            $cleaned = 0;

            foreach ($records as $record) {
                $this->totalProcessed++;

                $originalName = $record->name;
                $sanitizedName = $this->sanitizeName($originalName);

                if ($sanitizedName !== $originalName) {
                    $this->changes[] = [
                        'model' => $modelName,
                        'id' => $record->id,
                        'original' => $originalName,
                        'sanitized' => $sanitizedName,
                        'timestamp' => now(),
                    ];

                    if (! $this->option('dry-run')) {
                        // Update without triggering events/mutators to avoid conflicts
                        $modelClass::where('id', $record->id)->update(['name' => $sanitizedName]);
                    }

                    $cleaned++;
                    $this->totalCleaned++;

                    $this->warn("  🧹 {$modelName} #{$record->id}:");
                    $this->line('    From: '.$this->truncate($originalName, 80));
                    $this->line('    To:   '.$this->truncate($sanitizedName, 80));
                }
            }

            if ($cleaned > 0) {
                $action = $this->option('dry-run') ? 'would be sanitized' : 'sanitized';
                $this->info("  ✅ {$cleaned}/{$records->count()} records {$action}");
            } else {
                $this->info('  ✨ No invalid characters found');
            }

        } catch (\Exception $e) {
            $this->error("  ❌ Error processing {$modelName}: ".$e->getMessage());
        }
    }

    protected function sanitizeName(string $name): string
    {
        // Remove all characters that don't match the allowed pattern
        // Use the shared ValidationPatterns to ensure consistency
        $allowedPattern = str_replace(['/', '^', '$'], '', ValidationPatterns::NAME_PATTERN);
        $sanitized = preg_replace('/[^'.$allowedPattern.']+/', '', $name);

        // Clean up excessive whitespace but preserve other allowed characters
        $sanitized = preg_replace('/\s+/', ' ', $sanitized);
        $sanitized = trim($sanitized);

        // If result is empty, provide a default name
        if (empty($sanitized)) {
            $sanitized = 'sanitized-item';
        }

        return $sanitized;
    }

    protected function displaySummary(): void
    {
        $this->info("\n".str_repeat('=', 60));
        $this->info('📊 CLEANUP SUMMARY');
        $this->info(str_repeat('=', 60));

        $this->line("Records processed: {$this->totalProcessed}");
        $this->line("Records with invalid characters: {$this->totalCleaned}");

        if ($this->option('dry-run')) {
            $this->warn("\n🔍 DRY RUN - No changes were made to the database");
            $this->info('Run without --dry-run to apply these changes');
        } else {
            if ($this->totalCleaned > 0) {
                $this->info("\n✅ Database successfully sanitized!");
                $this->info('Changes logged to storage/logs/name-cleanup.log');
            } else {
                $this->info("\n✨ No cleanup needed - all names are valid!");
            }
        }
    }

    protected function logChanges(): void
    {
        $logFile = storage_path('logs/name-cleanup.log');
        $logData = [
            'timestamp' => now()->toISOString(),
            'total_processed' => $this->totalProcessed,
            'total_cleaned' => $this->totalCleaned,
            'changes' => $this->changes,
        ];

        file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT)."\n", FILE_APPEND);

        Log::info('Name Sanitization completed', [
            'total_processed' => $this->totalProcessed,
            'total_sanitized' => $this->totalCleaned,
            'changes_count' => count($this->changes),
        ]);
    }

    protected function createBackup(): void
    {
        $this->info('💾 Creating database backup...');

        try {
            $backupFile = storage_path('backups/name-cleanup-backup-'.now()->format('Y-m-d-H-i-s').'.sql');

            // Ensure backup directory exists
            if (! file_exists(dirname($backupFile))) {
                mkdir(dirname($backupFile), 0755, true);
            }

            $dbConfig = config('database.connections.'.config('database.default'));
            $command = sprintf(
                'pg_dump -h %s -p %s -U %s -d %s > %s',
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['username'],
                $dbConfig['database'],
                $backupFile
            );

            exec($command, $output, $returnCode);

            if ($returnCode === 0) {
                $this->info("✅ Backup created: {$backupFile}");
            } else {
                $this->warn('⚠️  Backup creation may have failed. Proceeding anyway...');
            }
        } catch (\Exception $e) {
            $this->warn('⚠️  Could not create backup: '.$e->getMessage());
            $this->warn('Proceeding without backup...');
        }
    }

    protected function truncate(string $text, int $length): string
    {
        return strlen($text) > $length ? substr($text, 0, $length).'...' : $text;
    }
}
