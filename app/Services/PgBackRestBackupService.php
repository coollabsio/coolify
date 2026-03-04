<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PgBackRestBackupService
{
    /**
     * Check if pgBackRest binary is present
     */
    public function isAvailable(): bool
    {
        exec('which pgbackrest', $output, $returnVar);
        return $returnVar === 0;
    }

    /**
     * Perform a full pgBackRest backup
     *
     * @param array|string $database  Database config or name
     * @param string       $destination Local path to store the backup artifact
     * @return array Backup metadata (path, type, metadata)
     * @throws RuntimeException on failure
     */
    public function backup($database, string $destination): array
    {
        $dbName = is_array($database) && isset($database['name'])
            ? $database['name']
            : env('DB_DATABASE');
        $dbHost = env('DB_HOST', '127.0.0.1');
        $dbPort = env('DB_PORT', '5432');
        $user = env('DB_USERNAME');
        $password = env('DB_PASSWORD');
        $repoPath = storage_path('app/backups/pgbackrest');

        // Build pgBackRest command for a full backup
        $cmd = sprintf(
            'PGPASSWORD=%s pgbackrest --stanza=%s --db-host=%s --db-port=%s --db-user=%s --repo1-path=%s backup',
            escapeshellarg($password),
            escapeshellarg($dbName),
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($user),
            escapeshellarg($repoPath)
        );
        exec($cmd, $output, $returnVar);
        if ($returnVar !== 0) {
            throw new RuntimeException('pgBackRest backup failed: '.implode("\n", $output));
        }

        // Determine latest backup artifact (example path, adjust if needed)
        $archiveDir = $repoPath.DIRECTORY_SEPARATOR.'archive'.DIRECTORY_SEPARATOR.$dbName;
        $files = glob($archiveDir.'/*.backup');
        if (empty($files)) {
            throw new RuntimeException('No backup file found in '.$archiveDir);
        }
        $latest = end($files);

        // Copy to desired destination
        Storage::disk('local')->copy($latest, $destination);

        return [
            'path' => $destination,
            'type' => 'pgbackrest',
            'metadata' => [
                'pgbackrest' => true,
                'stanza' => $dbName,
            ],
        ];
    }
}
