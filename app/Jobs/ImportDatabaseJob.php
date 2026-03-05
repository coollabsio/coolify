<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 7200; // 2 hours for large database imports

    public function __construct(
        public Server $server,
        public string $containerName,
        public string $filePath,
        public array $envVars,
        public bool $isNonRoot = false
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        try {
            $escapedContainer = escapeshellarg($this->containerName);
            $escapedPath = escapeshellarg($this->filePath);

            // Determine database type
            $isMariaDB = isset($this->envVars['MARIADB_ROOT_PASSWORD']) || isset($this->envVars['MARIADB_DATABASE']);
            $isMySQL = isset($this->envVars['MYSQL_ROOT_PASSWORD']) || isset($this->envVars['MYSQL_DATABASE']);

            if (! $isMariaDB && ! $isMySQL) {
                throw new \RuntimeException('Could not determine database type. Make sure this is a MySQL or MariaDB container.');
            }

            $rootPassword = $isMariaDB ? ($this->envVars['MARIADB_ROOT_PASSWORD'] ?? '') : ($this->envVars['MYSQL_ROOT_PASSWORD'] ?? '');
            $database = $isMariaDB ? ($this->envVars['MARIADB_DATABASE'] ?? '') : ($this->envVars['MYSQL_DATABASE'] ?? '');

            if (empty($rootPassword)) {
                throw new \RuntimeException('Root password not found in container environment variables.');
            }

            $dbCommand = $isMariaDB ? 'mariadb' : 'mysql';
            $passwordVar = $isMariaDB ? 'MARIADB_ROOT_PASSWORD' : 'MYSQL_ROOT_PASSWORD';
            $databaseVar = $isMariaDB ? 'MARIADB_DATABASE' : 'MYSQL_DATABASE';

            $isCompressed = str_ends_with(strtolower($this->filePath), '.gz') ||
                           str_ends_with(strtolower($this->filePath), '.zip');

            $escapedPassword = str_replace("'", "'\\''", $rootPassword);
            $escapedDatabaseName = ! empty($database) ? str_replace("'", "'\\''", $database) : '';

            $commandParts = [];
            $commandParts[] = "export {$passwordVar}='{$escapedPassword}'";
            if (! empty($database)) {
                $commandParts[] = "export {$databaseVar}='{$escapedDatabaseName}'";
            }

            if ($isCompressed && str_ends_with(strtolower($this->filePath), '.gz')) {
                $commandParts[] = "gunzip -c {$escapedPath}";
            } elseif ($isCompressed && str_ends_with(strtolower($this->filePath), '.zip')) {
                $commandParts[] = "unzip -p {$escapedPath}";
            } else {
                $commandParts[] = "cat {$escapedPath}";
            }

            if (! empty($database)) {
                $commandParts[] = "{$dbCommand} -u root --password=\${$passwordVar} \${$databaseVar}";
            } else {
                $commandParts[] = "{$dbCommand} -u root --password=\${$passwordVar}";
            }

            $fullCommand = implode(' | ', $commandParts);
            $importCommand = "docker exec {$escapedContainer} sh -c ".escapeshellarg($fullCommand);

            if ($this->isNonRoot) {
                $importCommand = "sudo {$importCommand}";
            }

            Log::info('Starting database import', [
                'container' => $this->containerName,
                'file' => $this->filePath,
                'database' => $database,
            ]);

            $output = instant_remote_process([$importCommand], $this->server, false);

            if ($output === false || (is_string($output) && str_contains(strtolower($output), 'error'))) {
                throw new \RuntimeException('Import command failed: '.($output ?: 'Unknown error'));
            }

            Log::info('Database import completed successfully', [
                'container' => $this->containerName,
                'file' => $this->filePath,
                'database' => $database,
            ]);
        } catch (\Throwable $e) {
            Log::error('Database import failed', [
                'container' => $this->containerName,
                'file' => $this->filePath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
