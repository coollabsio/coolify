<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class PgBackRestBackupService
{
    /**
     * Detect if pgBackRest binary is available on the host.
     */
    public static function isAvailable(): bool
    {
        $process = new Process(['which', 'pgbackrest']);
        $process->run();
        return $process->isSuccessful();
    }

    /**
     * Build a pgBackRest command for a full or incremental backup.
     *
     * @param array $config Associative array with required keys:
     *                      - dbPath          : Path to Postgres data directory
     *                      - repoPath        : Path to pgBackRest repository
     *                      - stanza          : pgBackRest stanza name
     *                      - type            : backup type ("full" | "incr")
     *                      - compress        : compression method (e.g. "lz4") – optional
     */
    public static function buildBackupCommand(array $config): array
    {
        $cmd = [
            'pgbackrest',
            '--stanza=' . ($config['stanza'] ?? 'coolify'),
            '--pg1-path=' . $config['dbPath'],
            '--repo1-path=' . $config['repoPath'],
            'backup',
        ];

        if (($config['type'] ?? 'full') === 'incr') {
            $cmd[] = '--type=incr';
        }

        if (!empty($config['compress'])) {
            $cmd[] = '--compress-type=' . $config['compress'];
        }

        return $cmd;
    }

    /**
     * Execute a pgBackRest backup with the provided configuration.
     *
     * @throws \RuntimeException When pgBackRest is not available or execution fails.
     */
    public function backup(array $config): void
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('pgBackRest binary not found – cannot perform backup');
        }

        $process = new Process(self::buildBackupCommand($config));
        $process->setTimeout(null);
        $process->run(function ($type, $buffer) {
            if (Process::ERR === $type) {
                logger()->error($buffer);
            } else {
                logger()->info($buffer);
            }
        });

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /**
     * Build a pgBackRest restore command (stub for future integration).
     */
    public static function buildRestoreCommand(array $config): array
    {
        $cmd = [
            'pgbackrest',
            '--stanza=' . ($config['stanza'] ?? 'coolify'),
            '--pg1-path=' . $config['dbPath'],
            '--repo1-path=' . $config['repoPath'],
            'restore',
        ];

        if (!empty($config['timestamp'])) {
            $cmd[] = '--target-action=promote';
            $cmd[] = '--target="' . $config['timestamp'] . '"';
        }

        return $cmd;
    }
}
