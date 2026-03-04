<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class PgBackRestBackupService
{
    protected string $stanza;
    protected string $repoType;
    protected string $repoPath;
    protected string $compression;

    public function __construct()
    {
        $this->stanza = config('database.connections.pgbackrest.stanza', 'coolify');
        $this->repoType = config('database.connections.pgbackrest.repo_type', 'local');
        $this->repoPath = config('database.connections.pgbackrest.repo_path', storage_path('app/backups'));
        $this->compression = config('database.connections.pgbackrest.compression', 'lz4');
    }

    public function isAvailable(): bool
    {
        $process = new Process(['which', 'pgbackrest']);
        $process->run();
        return $process->isSuccessful();
    }

    /**
     * Perform a pgBackRest backup for the given database
     * @param string $database
     * @param string $backupPath
     * @throws ProcessFailedException
     */
    public function backup(string $database, string $backupPath): void
    {
        $process = new Process([
            'pgbackrest',
            '--stanza='.$this->stanza,
            '--repo-type='.$this->repoType,
            '--repo-path='.$this->repoPath,
            '--db-include='.$database,
            '--compress='.$this->compression,
            'backup'
        ]);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }
}
