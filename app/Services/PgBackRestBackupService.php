<?php

namespace App\Services;

use App\Models\Backup;
use Illuminate\Support\Facades\Storage;

class PgBackRestBackupService
{
    protected $database;
    protected $backup;

    public function __construct($database, Backup $backup)
    {
        $this->database = $database;
        $this->backup = $backup;
    }

    /**
     * Run a full pgBackRest backup and archive the repo into a tar file.
     */
    public function backup(string $archivePath): bool
    {
        $dbName = $this->database->database;
        $dbUser = $this->database->username;
        $dbHost = $this->database->host;
        $dbPort = $this->database->port ?? 5432;
        $repo = storage_path('app/backups/pgbackrest_repo');

        // Ensure repository directory exists
        if (!is_dir($repo)) {
            mkdir($repo, 0755, true);
        }

        // Initialize stanza if needed
        shell_exec(sprintf(
            'pgbackrest --stanza=%s --repo1-path=%s stanza-create',
            escapeshellarg($dbName),
            escapeshellarg($repo)
        ));

        // Run full backup
        $cmd = sprintf(
            'pgbackrest --stanza=%s --db-host=%s --db-port=%s --db-user=%s --repo1-path=%s backup --type=full',
            escapeshellarg($dbName),
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            escapeshellarg($repo)
        );

        exec($cmd . ' 2>&1', $output, $returnCode);
        if ($returnCode !== 0) {
            // log errors
            file_put_contents(storage_path('logs/backup_errors.log'), implode("\n", $output), FILE_APPEND);
            return false;
        }

        // Archive the pgBackRest repo
        $phar = new \PharData($archivePath);
        $phar->buildFromDirectory($repo);

        return true;
    }
}
