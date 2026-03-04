<?php

namespace App\Services;

use App\Models\Database;
use Symfony\Component\Process\Process;
use Exception;

class PgBackRestBackupService
{
    protected string $repoPath;

    public function __construct()
    {
        $this->repoPath = storage_path('app/backups/pgbackrest_repo');
        if (! is_dir($this->repoPath)) {
            mkdir($this->repoPath, 0755, true);
        }
    }

    /**
     * Check if pgBackRest CLI is available
     */
    public function isAvailable(): bool
    {
        $process = Process::fromShellCommandline('command -v pgbackrest');
        $process->run();
        return $process->isSuccessful();
    }

    /**
     * Run a pgBackRest backup
     * @param string $stanza
     * @param string $type (full, differential, incremental)
     * @throws Exception on failure
     */
    public function backup(string $stanza = 'coolify', string $type = 'full'): void
    {
        $command = [
            'pgbackrest',
            "--repo1-path={$this->repoPath}",
            "--stanza={$stanza}",
            'backup',
            "--type={$type}",
        ];
        $process = new Process($command);
        $process->setTimeout(3600);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new Exception('pgBackRest backup failed: ' . $process->getErrorOutput());
        }
    }

    /**
     * Run a pgBackRest restore
     * @param string $stanza
     * @param string $target
     * @throws Exception on failure
     */
    public function restore(string $stanza = 'coolify', string $target = ''): void
    {
        $command = [
            'pgbackrest',
            "--repo1-path={$this->repoPath}",
            "--stanza={$stanza}",
            'restore',
            '--delta',
            $target !== '' ? "--target={$target}" : null,
        ];
        $command = array_filter($command);
        $process = new Process($command);
        $process->setTimeout(3600);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new Exception('pgBackRest restore failed: ' . $process->getErrorOutput());
        }
    }
}
