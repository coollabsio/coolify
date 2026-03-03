<?php

namespace App\Services;

use Exception;

class PgBackRestBackupService
{
    /**
     * Run a full or incremental backup using pgBackRest.
     *
     * @return array Output lines from pgBackRest
     * @throws Exception on failure
     */
    public function backup(): array
    {
        $stanza = config('database.pgbackrest_stanza', 'coolify');
        $repoPath = config('database.pgbackrest_repo_path', '/var/lib/pgbackrest');
        $cmd = sprintf(
            'pgbackrest --stanza=%s --repo1-path=%s backup',
            escapeshellarg($stanza),
            escapeshellarg($repoPath)
        );
        exec($cmd, $output, $status);
        if ($status !== 0) {
            throw new Exception('pgBackRest backup failed: ' . implode("\n", $output));
        }
        return $output;
    }

    /**
     * Restore a backup from pgBackRest to a target directory.
     *
     * @param string $targetDir
     * @return array Output lines from pgBackRest
     * @throws Exception on failure
     */
    public function restore(string $targetDir): array
    {
        $stanza = config('database.pgbackrest_stanza', 'coolify');
        $repoPath = config('database.pgbackrest_repo_path', '/var/lib/pgbackrest');
        $cmd = sprintf(
            'pgbackrest --stanza=%s --repo1-path=%s restore --delta --target-dir=%s',
            escapeshellarg($stanza),
            escapeshellarg($repoPath),
            escapeshellarg($targetDir)
        );
        exec($cmd, $output, $status);
        if ($status !== 0) {
            throw new Exception('pgBackRest restore failed: ' . implode("\n", $output));
        }
        return $output;
    }
}
