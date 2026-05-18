<?php

namespace App\Jobs;

use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StopDatabase;
use App\Models\DatabaseRestore;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\StandalonePostgresql;
use App\Notifications\Database\DatabaseRestoreFailed;
use App\Notifications\Database\DatabaseRestoreSuccess;
use App\Services\Backup\PgBackrestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PgBackrestRestoreJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200;

    public $maxExceptions = 1;

    public function __construct(
        public StandalonePostgresql $database,
        public DatabaseRestore $restore,
        public ?ScheduledDatabaseBackupExecution $execution = null,
        public ?string $targetTime = null
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $stanza = PgBackrestService::getStanzaName($this->database);
        $backupTimestamp = time();

        try {
            $this->restore->updateStatus('running', 'Starting PgBackRest restore.');

            $this->preflight($stanza);

            $this->restore->appendLog('Stopping PostgreSQL container.');
            StopDatabase::run($this->database, dockerCleanup: false);

            $this->restore->appendLog('Backing up current PGDATA before restore.');
            $backupPath = $this->backupCurrentPgData($backupTimestamp);

            try {
                $this->restore->appendLog('Clearing PGDATA directory.');
                $this->clearPgData();

                $this->restore->appendLog('Restoring via PgBackRest sidecar container.');
                $this->runRestoreSidecar($stanza);

                $this->restore->appendLog('Verifying restored database.');
                $this->verifyRestore();

                $this->restore->appendLog('Starting PostgreSQL after restore.');
                StartPostgresql::run($this->database);

                if ($backupPath) {
                    $this->restore->appendLog('Removing backup of previous database.');
                    $this->removePgDataBackup($backupPath);
                }

                $this->restore->updateStatus('success', 'PgBackRest restore completed successfully.');

                $team = $this->database->team();
                if ($team) {
                    $team->notify(new DatabaseRestoreSuccess(
                        $this->database,
                        $this->restore->target_label,
                        $this->targetTime
                    ));
                }
            } catch (Throwable $restoreError) {
                $this->restore->appendLog('Restore failed, attempting to recover from backup: '.$restoreError->getMessage());
                if ($backupPath) {
                    $this->recoverFromBackup($backupPath);
                }
                throw $restoreError;
            }
        } catch (Throwable $e) {
            Log::error('PgBackRest restore failed', [
                'database' => $this->database->uuid,
                'restore_id' => $this->restore->uuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->restore->updateStatus('failed', 'Restore failed: '.$e->getMessage());

            $team = $this->database->team();
            if ($team) {
                $team->notify(new DatabaseRestoreFailed(
                    $this->database,
                    $e->getMessage(),
                    $this->restore->target_label
                ));
            }

            try {
                $this->restore->appendLog('Attempting to restart PostgreSQL after failed restore.');
                StartPostgresql::run($this->database);
            } catch (Throwable $restartError) {
                $this->restore->appendLog('Failed to restart PostgreSQL: '.$restartError->getMessage());
            }

            throw $e;
        }
    }

    private function preflight(string $stanza): void
    {
        $this->restore->appendLog('Running pre-flight validation.');

        if (! $this->database->hasPgBackrestBackups()) {
            throw new RuntimeException('No PgBackRest backup configuration found for this database.');
        }

        $backup = $this->database->pgbackrestBackups()->where('enabled', true)->first();
        if (! $backup) {
            throw new RuntimeException('No enabled PgBackRest backup configuration found.');
        }

        $s3Repos = $backup->pgbackrestRepos()->where('type', 's3')->where('enabled', true)->get();
        foreach ($s3Repos as $s3Repo) {
            $s3 = $s3Repo->s3Storage;
            if (! $s3) {
                throw new RuntimeException("S3 storage configuration not found for repo {$s3Repo->repo_number}.");
            }

            try {
                $s3->testConnection(shouldSave: true);
            } catch (Throwable $e) {
                throw new RuntimeException("S3 connection test failed for repo {$s3Repo->repo_number}: ".$e->getMessage());
            }
        }

        $pgdataVolume = $this->database->pgdataVolume();
        if (! $pgdataVolume) {
            throw new RuntimeException('PGDATA volume not found.');
        }

        $repoVolume = $this->database->pgbackrestRepoVolume();
        $hasLocalRepo = $backup->hasLocalRepo();
        if (! $repoVolume && $hasLocalRepo) {
            throw new RuntimeException('PgBackRest repository volume not found.');
        }

        $this->restore->appendLog('Verifying backup exists in repository via sidecar container.');
        $info = $this->runInfoSidecar($stanza, $backup);

        if (! PgBackrestService::stanzaExists($info)) {
            throw new RuntimeException('PgBackRest stanza does not exist or is not healthy.');
        }

        if (! PgBackrestService::hasBackups($info)) {
            throw new RuntimeException('No backups found in PgBackRest repository.');
        }

        if ($this->execution && $this->execution->pgbackrest_label) {
            $targetBackup = PgBackrestService::findBackupByLabel($info, $this->execution->pgbackrest_label);
            if (! $targetBackup) {
                throw new RuntimeException("Backup with label '{$this->execution->pgbackrest_label}' not found in repository.");
            }
            $this->restore->appendLog("Target backup verified: {$this->execution->pgbackrest_label}");
        } else {
            $latestBackup = PgBackrestService::getLatestBackup($info);
            if ($latestBackup) {
                $this->restore->appendLog("Will restore from latest backup: {$latestBackup['label']}");
            }
        }

        $this->restore->appendLog('Pre-flight validation passed.');
    }

    private function runInfoSidecar(string $stanza, $backup): array
    {
        $server = $this->database->destination->server;
        $configDir = $this->getHostPath($this->database->workdir().'/pgbackrest');
        $network = $this->database->destination->network;

        $mounts = [];
        $envPieces = [];

        $repoVolume = $this->database->pgbackrestRepoVolume();
        if ($repoVolume) {
            $repoMount = $repoVolume->host_path ?: $repoVolume->name;
            $mounts[] = '-v '.escapeshellarg($repoMount.':/var/lib/pgbackrest');
        }

        $mounts[] = '-v '.escapeshellarg($configDir.':/etc/pgbackrest:ro');

        $s3EnvVars = PgBackrestService::buildS3EnvVars($backup);
        foreach ($s3EnvVars as $key => $value) {
            $envPieces[] = '-e '.$key.'='.escapeshellarg($value);
        }

        $infoCmd = PgBackrestService::buildInfoCommand($stanza, true);
        $sidecarName = 'pgbackrest-info-'.$this->database->uuid.'-'.time();

        $cmd = sprintf(
            'docker run --rm --name %s --network %s %s %s %s sh -c %s 2>&1',
            escapeshellarg($sidecarName),
            escapeshellarg($network),
            implode(' ', $envPieces),
            implode(' ', $mounts),
            escapeshellarg($this->getSidecarImage()),
            escapeshellarg($this->getInstallAndRunCommand($infoCmd))
        );

        $output = instant_remote_process([$cmd], $server, false, false, 120, disableMultiplexing: true);

        if ($output === null || $output === '') {
            throw new RuntimeException('Failed to get PgBackRest info - command returned no output. Command: '.$cmd);
        }

        $jsonStart = strpos($output, '[');
        if ($jsonStart !== false) {
            $output = substr($output, $jsonStart);
        }

        $info = PgBackrestService::parseInfoJson($output);
        if ($info === null) {
            throw new RuntimeException('Failed to parse PgBackRest info output: '.$output);
        }

        return $info;
    }

    private function backupCurrentPgData(int $timestamp): ?string
    {
        $server = $this->database->destination->server;
        $pgdataVolume = $this->database->pgdataVolume();

        if (! $pgdataVolume) {
            throw new RuntimeException('PGDATA volume not found.');
        }

        $volumeName = $pgdataVolume->host_path ?: $pgdataVolume->name;
        $backupVolumeName = "{$pgdataVolume->name}_backup_{$timestamp}";

        $sourceMount = escapeshellarg($volumeName.':/data');
        $checkCmd = 'docker run --rm -v '.$sourceMount." alpine sh -c 'test -n \"\$(ls -A /data 2>/dev/null)\" && echo OK || echo EMPTY'";
        $checkResult = instant_remote_process([$checkCmd], $server, false, false, 30, disableMultiplexing: true);

        if (trim($checkResult) === 'EMPTY') {
            $this->restore->appendLog('No existing PGDATA to backup (directory is empty).');

            return null;
        }

        instant_remote_process(['docker volume create '.escapeshellarg($backupVolumeName)], $server, false, false, 30, disableMultiplexing: true);

        $sourceReadOnlyMount = escapeshellarg($volumeName.':/source:ro');
        $backupMount = escapeshellarg($backupVolumeName.':/backup');
        $copyCmd = 'docker run --rm -v '.$sourceReadOnlyMount.' -v '.$backupMount." alpine sh -c 'cp -a /source/. /backup/'";
        instant_remote_process([$copyCmd], $server, true, false, $this->timeout, disableMultiplexing: true);

        $verifyCmd = 'docker run --rm -v '.$backupMount." alpine sh -c 'test -n \"\$(ls -A /backup 2>/dev/null)\" && echo OK'";
        $result = instant_remote_process([$verifyCmd], $server, false, false, 30, disableMultiplexing: true);

        if (trim($result) !== 'OK') {
            throw new RuntimeException('PGDATA backup verification failed: backup volume is empty or inaccessible.');
        }

        $this->restore->appendLog("PGDATA backed up to volume: {$backupVolumeName}");

        return $backupVolumeName;
    }

    private function recoverFromBackup(string $backupVolumeName): void
    {
        try {
            $server = $this->database->destination->server;
            $pgdataVolume = $this->database->pgdataVolume();

            if (! $pgdataVolume) {
                $this->restore->appendLog('Warning: PGDATA volume not found, cannot recover from backup.');

                return;
            }

            $volumeName = $pgdataVolume->host_path ?: $pgdataVolume->name;

            $this->restore->appendLog('Recovering PGDATA from backup...');

            $dataMount = escapeshellarg($volumeName.':/data');
            $clearCmd = 'docker run --rm -v '.$dataMount." alpine sh -c 'rm -rf /data/* /data/.[!.]* /data/..?* 2>/dev/null || true'";
            instant_remote_process([$clearCmd], $server, false, false, 60, disableMultiplexing: true);

            $sourceMount = escapeshellarg($backupVolumeName.':/source:ro');
            $copyCmd = 'docker run --rm -v '.$sourceMount.' -v '.$dataMount." alpine sh -c 'cp -a /source/. /data/'";
            instant_remote_process([$copyCmd], $server, true, false, $this->timeout, disableMultiplexing: true);

            $this->restore->appendLog('PGDATA recovered from backup.');
        } catch (Throwable $e) {
            $this->restore->appendLog('Failed to recover from backup: '.$e->getMessage());
        }
    }

    private function removePgDataBackup(string $backupVolumeName): void
    {
        try {
            $server = $this->database->destination->server;

            instant_remote_process(['docker volume rm '.escapeshellarg($backupVolumeName).' 2>/dev/null || true'], $server, false, false, 60, disableMultiplexing: true);

            $this->restore->appendLog('Temporary backup volume removed.');
        } catch (Throwable $e) {
            $this->restore->appendLog('Warning: Failed to remove temporary backup volume: '.$e->getMessage());
        }
    }

    private function clearPgData(): void
    {
        $server = $this->database->destination->server;
        $pgdataVolume = $this->database->pgdataVolume();

        if (! $pgdataVolume) {
            throw new RuntimeException('PGDATA volume not found.');
        }

        $mount = $pgdataVolume->host_path ?: $pgdataVolume->name;

        $dataMount = escapeshellarg($mount.':/data');
        $rmCmd = 'docker run --rm -v '.$dataMount." alpine sh -c 'rm -rf /data/* /data/.[!.]* /data/..?* 2>/dev/null || true'";
        instant_remote_process([$rmCmd], $server, false, false, $this->timeout, disableMultiplexing: true);

        $this->restore->appendLog('PGDATA directory cleared.');
    }

    private function verifyRestore(): void
    {
        try {
            $pgdataVolume = $this->database->pgdataVolume();
            if (! $pgdataVolume) {
                throw new RuntimeException('PGDATA volume not found.');
            }

            $server = $this->database->destination->server;
            $mount = $pgdataVolume->host_path ?: $pgdataVolume->name;

            $dataMount = escapeshellarg($mount.':/data');
            $checkCmd = 'docker run --rm -v '.$dataMount." alpine test -f /data/PG_VERSION && echo 'OK' || echo 'FAIL'";
            $result = instant_remote_process([$checkCmd], $server, false, false, 30, disableMultiplexing: true);

            if (trim($result) !== 'OK') {
                throw new RuntimeException('Restored PGDATA does not contain valid PostgreSQL data (PG_VERSION not found).');
            }

            $this->restore->appendLog('Restored database verified successfully.');
        } catch (Throwable $e) {
            throw new RuntimeException('Database verification failed: '.$e->getMessage());
        }
    }

    private function runRestoreSidecar(string $stanza): void
    {
        $server = $this->database->destination->server;
        $configDir = $this->getHostPath($this->database->workdir().'/pgbackrest');
        $network = $this->database->destination->network;

        $backup = $this->database->pgbackrestBackups()->where('enabled', true)->first();
        if (! $backup) {
            throw new RuntimeException('No enabled PgBackRest backup configuration found.');
        }

        $pgdataVolume = $this->database->pgdataVolume();
        $repoVolume = $this->database->pgbackrestRepoVolume();

        $pgdataMount = $pgdataVolume->host_path ?: $pgdataVolume->name;

        $mounts = [];
        $mounts[] = '-v '.escapeshellarg($pgdataMount.':'.PgBackrestService::PGDATA_PATH);

        if ($repoVolume) {
            $repoMount = $repoVolume->host_path ?: $repoVolume->name;
            $mounts[] = '-v '.escapeshellarg($repoMount.':/var/lib/pgbackrest');
        }

        $mounts[] = '-v '.escapeshellarg($configDir.':/etc/pgbackrest:ro');

        $envPieces = ['-e PGBACKREST_PG1_PATH='.escapeshellarg(PgBackrestService::PGDATA_PATH)];

        $s3EnvVars = PgBackrestService::buildS3EnvVars($backup);
        foreach ($s3EnvVars as $key => $value) {
            $envPieces[] = '-e '.$key.'='.escapeshellarg($value);
        }

        $restoreCmd = PgBackrestService::buildRestoreCommand(
            $stanza,
            $this->execution?->pgbackrest_label,
            $this->targetTime,
            'info'
        );

        $sidecarName = 'pgbackrest-restore-'.$this->database->uuid.'-'.time();

        $fullRestoreScript = $this->getInstallAndRunCommand($restoreCmd);

        $cmd = sprintf(
            'docker run --rm --name %s --network %s %s %s %s sh -c %s 2>&1',
            escapeshellarg($sidecarName),
            escapeshellarg($network),
            implode(' ', $envPieces),
            implode(' ', $mounts),
            escapeshellarg($this->getSidecarImage()),
            escapeshellarg($fullRestoreScript)
        );

        $output = instant_remote_process([$cmd], $server, true, false, $this->timeout, disableMultiplexing: true);

        $this->restore->appendLog('Restore output: '.substr($output, 0, 2000));
    }

    private function getSidecarImage(): string
    {
        return $this->database->image;
    }

    private function getInstallAndRunCommand(string $command): string
    {
        return PgBackrestService::buildInstallAndSetupCommand($command);
    }

    /**
     * Convert a path from the SSH target perspective to the Docker host perspective.
     */
    private function getHostPath(string $path): string
    {
        return convertPathToDockerHost($path);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('scheduled-errors')->error('PgBackRest restore permanently failed', [
            'job' => 'PgBackrestRestoreJob',
            'database' => $this->database->uuid,
            'restore_id' => $this->restore->uuid,
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);

        $this->restore->updateStatus('failed', 'Restore job permanently failed: '.($exception?->getMessage() ?? 'Unknown error'));

        $team = $this->database->team();
        if ($team) {
            $team->notify(new DatabaseRestoreFailed(
                $this->database,
                $exception?->getMessage() ?? 'Unknown error',
                $this->restore->target_label
            ));
        }
    }
}
