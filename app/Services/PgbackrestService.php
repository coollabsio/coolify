<?php

namespace App\Services;

use App\Actions\Database\Pgbackrest\GeneratePgbackrestConfig;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use Illuminate\Support\Collection;

/**
 * Centralized service for all pgBackRest operations.
 *
 * Handles backup listing, validation, restore, and delete operations.
 * Works whether the PostgreSQL container is running or stopped by
 * automatically switching between main container exec and temp container.
 *
 * Supports both local (posix) and S3 repositories.
 */
class PgbackrestService
{
    private StandalonePostgresql $database;

    private ?Server $server = null;

    private ?array $cachedMounts = null;

    public function __construct(StandalonePostgresql $database)
    {
        $this->database = $database;
        $this->server = $this->resolveServer();
    }

    private function resolveServer(): ?Server
    {
        try {
            return $this->database->destination->server ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function for(StandalonePostgresql $database): self
    {
        return new self($database);
    }

    /**
     * Check if pgBackRest is enabled for this database.
     */
    public function isEnabled(): bool
    {
        return $this->database->isPgbackrestEnabled();
    }

    /**
     * Check if using S3 repository.
     */
    public function isS3Repo(): bool
    {
        if ($this->database->pgbackrestRepos()->where('type', 's3')->exists()) {
            return true;
        }

        $legacyType = $this->database->pgbackrest_repo_type ?? 'posix';

        return in_array($legacyType, ['s3', 's3+posix'], true);
    }

    /**
     * Check if has local repository.
     */
    public function hasLocalRepo(): bool
    {
        if ($this->database->pgbackrestRepos()->where('type', 'posix')->exists()) {
            return true;
        }

        $legacyType = $this->database->pgbackrest_repo_type ?? 'posix';

        return in_array($legacyType, ['posix', 's3+posix'], true);
    }

    /**
     * Check if the PostgreSQL container is currently running.
     */
    public function isContainerRunning(): bool
    {
        if (! $this->server) {
            return false;
        }

        $containerName = $this->database->uuid;
        $nameFilter = '^/'.$containerName.'$';

        $result = instant_remote_process(
            ['docker ps -q -f name='.escapeshellarg($nameFilter)],
            $this->server,
            throwError: false,
        );

        return ! blank(trim($result));
    }

    /**
     * Get the stanza name for this database.
     */
    public function getStanzaName(): string
    {
        return $this->database->getPgbackrestStanzaName();
    }

    /**
     * Resolve pgBackRest-related host paths and data volume.
     *
     * Works when container is running, stopped, or even deleted.
     * Checks multiple fallback paths for dev vs production environments.
     */
    public function getMounts(): array
    {
        if ($this->cachedMounts !== null) {
            return $this->cachedMounts;
        }

        if (! $this->server) {
            return $this->cachedMounts = [
                'data_volume' => null,
                'pgbackrest_config' => null,
                'pgbackrest_repo' => null,
            ];
        }

        $containerName = $this->database->uuid;
        $dataVolume = "postgres-data-{$containerName}";

        $inspectCmd = "docker inspect {$containerName} --format '{{range .Mounts}}{{if eq .Destination \"/etc/pgbackrest\"}}PGBACKREST_CONFIG={{.Source}}{{end}}{{if eq .Destination \"/var/lib/pgbackrest\"}}PGBACKREST_REPO={{.Source}}{{end}}{{end}}' 2>/dev/null || true";
        $output = instant_remote_process([$inspectCmd], $this->server, throwError: false);

        $pgbackrestConfig = '';
        $pgbackrestRepo = '';

        if (preg_match('/PGBACKREST_CONFIG=(.+?)(?=PGBACKREST_|$)/', $output ?? '', $matches)) {
            $pgbackrestConfig = trim($matches[1]);
        }
        if (preg_match('/PGBACKREST_REPO=(.+?)(?=PGBACKREST_|$)/', $output ?? '', $matches)) {
            $pgbackrestRepo = trim($matches[1]);
        }

        if (empty($pgbackrestConfig) || empty($pgbackrestRepo)) {
            $resolved = $this->resolvePathsFromFilesystem($containerName);
            $pgbackrestConfig = $pgbackrestConfig ?: $resolved['config'];
            $pgbackrestRepo = $pgbackrestRepo ?: $resolved['repo'];
        }

        return $this->cachedMounts = [
            'data_volume' => $dataVolume,
            'pgbackrest_config' => $pgbackrestConfig ?: null,
            'pgbackrest_repo' => $pgbackrestRepo ?: null,
        ];
    }

    /**
     * Clear the cached mounts (useful after container restart).
     */
    public function clearMountCache(): self
    {
        $this->cachedMounts = null;

        return $this;
    }

    /**
     * Execute a pgBackRest command, automatically choosing execution method.
     *
     * Uses docker exec when container is running, otherwise uses temp container.
     *
     * @param  string  $args  pgBackRest arguments (e.g., '--stanza=db-xxx info')
     * @param  bool  $needsDataDir  Whether to mount the data volume (required for restore)
     * @param  bool  $throwError  Whether to throw on non-zero exit code
     * @return string Command output
     *
     * @throws \RuntimeException If command fails and $throwError is true
     */
    public function execute(string $args, bool $needsDataDir = false, bool $throwError = false): string
    {
        if (! $this->server) {
            if ($throwError) {
                throw new \RuntimeException('Server not available for pgBackRest operation.');
            }

            return '';
        }

        if ($this->isContainerRunning() && ! $needsDataDir) {
            return $this->executeInMainContainer($args, $throwError);
        }

        return $this->executeInTempContainer($args, $needsDataDir, $throwError);
    }

    /**
     * Get raw pgBackRest info as JSON array.
     */
    public function getInfo(): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $stanza = escapeshellarg($this->getStanzaName());

        try {
            $output = $this->execute("--stanza={$stanza} info --output=json", false, false);

            if (blank($output)) {
                return null;
            }

            $info = json_decode($output, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($info)) {
                return null;
            }

            return $info;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get pgBackRest info using temp container context (ensures same context as restore).
     */
    public function getInfoFromTempContainer(): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $stanza = escapeshellarg($this->getStanzaName());

        try {
            $output = $this->executeInTempContainer("--stanza={$stanza} info --output=json", false, false);

            if (blank($output)) {
                return null;
            }

            $info = json_decode($output, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($info)) {
                return null;
            }

            return $info;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get list of available backups.
     */
    public function getBackupList(): Collection
    {
        $info = $this->getInfo();

        if (empty($info) || ! isset($info[0]['backup']) || ! is_array($info[0]['backup'])) {
            return collect();
        }

        return collect($info[0]['backup'])->map(function ($backup) {
            $dbList = [];
            if (isset($backup['database']) && is_array($backup['database'])) {
                $dbList = array_values(array_filter(array_column($backup['database'], 'name')));
            }

            $size = (int) ($backup['info']['size'] ?? 0);
            $repoSize = (int) ($backup['info']['repository']['size'] ?? 0);

            return [
                'label' => $backup['label'] ?? null,
                'type' => $backup['type'] ?? null,
                'size' => $size,
                'size_formatted' => formatBytes($size),
                'repository_size' => $repoSize,
                'repository_size_formatted' => formatBytes($repoSize),
                'timestamp_start' => $backup['timestamp']['start'] ?? null,
                'timestamp_stop' => $backup['timestamp']['stop'] ?? null,
                'started_at' => isset($backup['timestamp']['start']) ? \Carbon\Carbon::createFromTimestamp($backup['timestamp']['start']) : null,
                'finished_at' => isset($backup['timestamp']['stop']) ? \Carbon\Carbon::createFromTimestamp($backup['timestamp']['stop']) : null,
                'database_list' => $dbList,
                'prior' => $backup['prior'] ?? null,
            ];
        })->reverse()->values();
    }

    /**
     * Get backup list using temp container context (same as restore will use).
     */
    public function getBackupListFromTempContainer(): Collection
    {
        $info = $this->getInfoFromTempContainer();

        if (empty($info) || ! isset($info[0]['backup']) || ! is_array($info[0]['backup'])) {
            return collect();
        }

        return collect($info[0]['backup'])->map(function ($backup) {
            return [
                'label' => $backup['label'] ?? null,
                'type' => $backup['type'] ?? null,
                'timestamp_start' => $backup['timestamp']['start'] ?? null,
                'timestamp_stop' => $backup['timestamp']['stop'] ?? null,
            ];
        })->reverse()->values();
    }

    /**
     * Get a specific backup by label.
     */
    public function getBackupByLabel(string $label): ?array
    {
        return $this->getBackupList()->firstWhere('label', $label);
    }

    /**
     * Get the latest backup.
     */
    public function getLatestBackup(): ?array
    {
        return $this->getBackupList()->first();
    }

    /**
     * Check if a backup exists.
     */
    public function backupExists(string $label): bool
    {
        return $this->getBackupByLabel($label) !== null;
    }

    /**
     * Get stanza status.
     */
    public function getStanzaStatus(): array
    {
        if (! $this->isEnabled()) {
            return ['status' => 'disabled', 'message' => 'pgBackRest is not enabled'];
        }

        $info = $this->getInfo();

        if ($info === null) {
            return ['status' => 'error', 'message' => 'Failed to get pgBackRest info'];
        }

        if (empty($info) || ! isset($info[0]) || ! is_array($info[0])) {
            return ['status' => 'no_stanza', 'message' => 'No stanza configured'];
        }

        $stanzaInfo = $info[0];
        $status = $stanzaInfo['status'] ?? [];

        if (isset($status['code']) && $status['code'] !== 0) {
            return [
                'status' => 'error',
                'message' => $status['message'] ?? 'Unknown error',
                'code' => $status['code'],
            ];
        }

        $backupCount = count($stanzaInfo['backup'] ?? []);

        return [
            'status' => 'ok',
            'message' => "Stanza is healthy with {$backupCount} backup(s)",
            'backup_count' => $backupCount,
            'cipher' => $stanzaInfo['cipher'] ?? 'none',
        ];
    }

    /**
     * Calculate total size of all backups in repository.
     */
    public function getTotalSize(): int
    {
        return $this->getBackupList()->sum('repository_size');
    }

    /**
     * Check if a backup can be deleted (has no dependents).
     */
    public function isBackupDeletable(string $label): array
    {
        $backups = $this->getBackupList();

        $backup = $backups->firstWhere('label', $label);
        if (! $backup) {
            return ['deletable' => false, 'reason' => 'Backup not found in repository', 'backup' => null];
        }

        $dependents = $this->findAllDependents($label, $backups);

        if ($dependents->isNotEmpty()) {
            $dependentLabels = $dependents->pluck('label')->join(', ');

            return [
                'deletable' => false,
                'reason' => "This backup has dependent backups that would also be deleted: {$dependentLabels}",
                'dependents' => $dependents->pluck('label')->toArray(),
                'backup' => $backup,
            ];
        }

        return ['deletable' => true, 'reason' => null, 'backup' => $backup];
    }

    /**
     * Find all backups that depend on a given backup (recursively).
     */
    private function findAllDependents(string $label, Collection $backups): Collection
    {
        $directDependents = $backups->filter(fn ($b) => ($b['prior'] ?? null) === $label);

        $allDependents = collect();
        foreach ($directDependents as $dependent) {
            $allDependents->push($dependent);
            $nestedDependents = $this->findAllDependents($dependent['label'], $backups);
            $allDependents = $allDependents->merge($nestedDependents);
        }

        return $allDependents;
    }

    /**
     * Delete a backup from the repository.
     *
     * Will not delete if the backup has dependent incremental/differential backups.
     */
    public function deleteBackup(string $label): array
    {
        $deletableCheck = $this->isBackupDeletable($label);

        if (! $deletableCheck['deletable']) {
            return ['success' => false, 'message' => $deletableCheck['reason']];
        }

        $stanza = escapeshellarg($this->getStanzaName());
        $escapedLabel = escapeshellarg($label);

        try {
            $this->execute("--stanza={$stanza} --set={$escapedLabel} expire", false, false);

            return ['success' => true, 'message' => 'Backup deleted from pgBackRest repository'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => "Failed to expire backup: {$e->getMessage()}"];
        }
    }

    /**
     * Validate that a restore operation can proceed (basic validation).
     */
    public function validateRestore(?string $backupLabel = null): array
    {
        if (! $this->isEnabled()) {
            return ['valid' => false, 'message' => 'pgBackRest is not enabled'];
        }

        if ($backupLabel && ! $this->backupExists($backupLabel)) {
            return ['valid' => false, 'message' => 'Backup not found in pgBackRest repository'];
        }

        return ['valid' => true, 'message' => 'Restore can proceed'];
    }

    /**
     * Deep validation of restore operation before any destructive actions.
     *
     * This runs in the SAME context (temp container) as the actual restore,
     * ensuring that if validation passes, the restore will have access to
     * the backup.
     *
     * @param  string|null  $backupLabel  Specific backup to restore, or null for latest
     * @param  string|null  $targetTime  Point-in-time target, or null for immediate
     * @return array{valid: bool, message: string, diagnostics?: array}
     */
    public function validateRestoreDeep(?string $backupLabel = null, ?string $targetTime = null): array
    {
        $diagnostics = [
            'stanza' => $this->getStanzaName(),
            'has_local_repo' => $this->hasLocalRepo(),
            'has_s3_repo' => $this->isS3Repo(),
            'backup_label' => $backupLabel ?? 'latest',
            'target_time' => $targetTime,
        ];

        if (! $this->isEnabled()) {
            return [
                'valid' => false,
                'message' => 'pgBackRest is not enabled for this database.',
                'diagnostics' => $diagnostics,
            ];
        }

        if ($this->isS3Repo() && ! GeneratePgbackrestConfig::isS3ConfigComplete($this->database)) {
            return [
                'valid' => false,
                'message' => 'S3 configuration is incomplete. Please provide bucket, endpoint, region, and credentials.',
                'diagnostics' => $diagnostics,
            ];
        }

        $mounts = $this->getMounts();
        $diagnostics['mounts'] = $mounts;

        if (empty($mounts['pgbackrest_config'])) {
            return [
                'valid' => false,
                'message' => 'pgBackRest configuration path could not be resolved. Ensure the database container has been started at least once with pgBackRest enabled.',
                'diagnostics' => $diagnostics,
            ];
        }

        if ($this->hasLocalRepo() && empty($mounts['pgbackrest_repo'])) {
            return [
                'valid' => false,
                'message' => 'pgBackRest repository path could not be resolved for local repository.',
                'diagnostics' => $diagnostics,
            ];
        }

        if (empty($mounts['data_volume'])) {
            return [
                'valid' => false,
                'message' => 'Data volume could not be resolved.',
                'diagnostics' => $diagnostics,
            ];
        }

        try {
            $stanza = escapeshellarg($this->getStanzaName());
            $output = $this->executeInTempContainer("--stanza={$stanza} info --output=json", false, true);
            $info = json_decode($output, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($info)) {
                $rawOutput = trim($output ?? '');
                $errorHint = '';

                if (str_contains($rawOutput, 'unable to find stanza')) {
                    $errorHint = ' The stanza may not have been created yet. Try running a backup first.';
                } elseif (str_contains($rawOutput, 'S3') || str_contains($rawOutput, 's3')) {
                    $errorHint = ' There may be an issue with S3 credentials or connectivity.';
                } elseif (empty($rawOutput)) {
                    $errorHint = ' No output received - the pgBackRest container may have failed to start.';
                }

                return [
                    'valid' => false,
                    'message' => 'Failed to parse pgBackRest info output.'.$errorHint."\n\nRaw output: ".substr($rawOutput, 0, 300),
                    'diagnostics' => array_merge($diagnostics, ['raw_output' => substr($rawOutput, 0, 500)]),
                ];
            }

            if (empty($info) || ! isset($info[0])) {
                return [
                    'valid' => false,
                    'message' => "Stanza '{$this->getStanzaName()}' not found in repository. Ensure stanza-create has been run.",
                    'diagnostics' => $diagnostics,
                ];
            }

            $stanzaInfo = $info[0];
            $status = $stanzaInfo['status'] ?? [];

            if (isset($status['code']) && $status['code'] !== 0) {
                $statusMessage = $status['message'] ?? 'Unknown stanza error';

                return [
                    'valid' => false,
                    'message' => "Stanza error: {$statusMessage}",
                    'diagnostics' => array_merge($diagnostics, ['stanza_status' => $status]),
                ];
            }

            $backups = $stanzaInfo['backup'] ?? [];
            $diagnostics['backup_count'] = count($backups);

            if (empty($backups)) {
                return [
                    'valid' => false,
                    'message' => 'No backups found in the repository. Create a backup before attempting restore.',
                    'diagnostics' => $diagnostics,
                ];
            }

            if ($backupLabel) {
                $foundBackup = collect($backups)->firstWhere('label', $backupLabel);
                if (! $foundBackup) {
                    $availableLabels = collect($backups)->pluck('label')->take(5)->join(', ');

                    return [
                        'valid' => false,
                        'message' => "Backup '{$backupLabel}' not found in repository. It may have been expired by retention policy. Available backups: {$availableLabels}",
                        'diagnostics' => array_merge($diagnostics, ['available_labels' => collect($backups)->pluck('label')->toArray()]),
                    ];
                }
                $diagnostics['validated_backup'] = $foundBackup['label'];
            }

            if ($targetTime) {
                $earliestStart = collect($backups)->min('timestamp.start');
                $latestStop = collect($backups)->max('timestamp.stop');

                $diagnostics['earliest_backup'] = $earliestStart ? date('Y-m-d H:i:s', $earliestStart) : null;
                $diagnostics['latest_backup'] = $latestStop ? date('Y-m-d H:i:s', $latestStop) : null;
            }

        } catch (\RuntimeException $e) {
            $errorMessage = $e->getMessage();

            if (str_contains($errorMessage, 'S3') || str_contains($errorMessage, 's3')) {
                return [
                    'valid' => false,
                    'message' => 'Unable to connect to S3 repository. Verify S3 endpoint, bucket, region, and credentials.',
                    'diagnostics' => array_merge($diagnostics, ['error' => $errorMessage]),
                ];
            }

            if (str_contains($errorMessage, 'stanza') || str_contains($errorMessage, 'could not find')) {
                return [
                    'valid' => false,
                    'message' => "Stanza '{$this->getStanzaName()}' not found. The repository may not be initialized for this database.",
                    'diagnostics' => array_merge($diagnostics, ['error' => $errorMessage]),
                ];
            }

            return [
                'valid' => false,
                'message' => "Pre-flight check failed: {$this->formatErrorMessage($e)}",
                'diagnostics' => array_merge($diagnostics, ['error' => $errorMessage]),
            ];
        }

        return [
            'valid' => true,
            'message' => 'All pre-flight checks passed. Restore can proceed safely.',
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Verify repository structure exists (for local repos).
     */
    public function verifyRepositoryStructure(): array
    {
        if ($this->isS3Repo()) {
            return ['valid' => true, 'message' => 'S3 repository structure is managed remotely.'];
        }

        $mounts = $this->getMounts();
        if (empty($mounts['pgbackrest_repo'])) {
            return ['valid' => false, 'message' => 'Repository path not resolved.'];
        }

        $stanza = $this->getStanzaName();
        $repoPath = $mounts['pgbackrest_repo'];

        $checkCmd = "test -d {$repoPath}/backup/{$stanza} && echo 'EXISTS' || echo 'MISSING'";
        $result = instant_remote_process([$checkCmd], $this->server, throwError: false);

        if (trim($result) !== 'EXISTS') {
            return [
                'valid' => false,
                'message' => "Repository structure not found at {$repoPath}/backup/{$stanza}. Stanza may not be initialized.",
            ];
        }

        return ['valid' => true, 'message' => 'Repository structure verified.'];
    }

    /**
     * Stop the PostgreSQL container.
     */
    public function stopContainer(int $timeout = 30): void
    {
        if (! $this->server) {
            return;
        }

        $containerName = $this->database->uuid;
        $stopCmd = "docker stop -t {$timeout} {$containerName} 2>/dev/null || true";
        instant_remote_process([$stopCmd], $this->server, throwError: false);
        sleep(2);
    }

    /**
     * Clear the PostgreSQL data directory using a temporary container.
     *
     * This ensures PGDATA is completely empty before restore.
     * Uses a thorough rm pattern to handle hidden files.
     */
    public function clearDataDirectory(): void
    {
        if (! $this->server) {
            return;
        }

        $mounts = $this->getMounts();
        if (empty($mounts['data_volume'])) {
            throw new \RuntimeException('Data volume not found');
        }

        $dataVolume = $mounts['data_volume'];

        $clearCmd = "docker run --rm -v {$dataVolume}:/data alpine sh -c '".
            'find /data -mindepth 1 -delete 2>/dev/null; '.
            'echo CLEARED'.
            "' 2>&1";

        $result = instant_remote_process([$clearCmd], $this->server, throwError: false);

        if (! str_contains($result ?? '', 'CLEARED')) {
            throw new \RuntimeException('Failed to clear data directory: '.$result);
        }
    }

    /**
     * Build the pgBackRest restore command.
     *
     * @param  bool  $includePaths  Include explicit paths (needed for temp container)
     */
    public function buildRestoreCommand(?string $backupLabel = null, ?string $targetTime = null, bool $includePaths = false): string
    {
        $stanza = escapeshellarg($this->getStanzaName());
        $command = "--stanza={$stanza}";

        if ($includePaths) {
            $command .= ' --pg1-path=/var/lib/postgresql/data';
            if ($this->hasLocalRepo()) {
                $command .= ' --repo1-path=/var/lib/pgbackrest';
            }
        }

        if ($backupLabel) {
            $command .= ' --set='.escapeshellarg($backupLabel);
        }

        if ($targetTime) {
            $command .= ' --type=time --target='.escapeshellarg($targetTime);
        } else {
            $command .= ' --type=immediate';
        }

        $command .= ' --target-action=promote --delta --link-all restore';

        return $command;
    }

    /**
     * Execute a restore operation.
     *
     * @throws \RuntimeException If restore fails
     */
    public function restore(?string $backupLabel = null, ?string $targetTime = null): string
    {
        $command = $this->buildRestoreCommand($backupLabel, $targetTime, includePaths: true);

        return $this->execute($command, needsDataDir: true, throwError: true);
    }

    /**
     * Execute pgBackRest inside the main PostgreSQL container.
     */
    private function executeInMainContainer(string $args, bool $throwError): string
    {
        $containerName = $this->database->uuid;
        $cmd = "docker exec {$containerName} su postgres -c 'pgbackrest {$args}' 2>&1";

        return instant_remote_process([$cmd], $this->server, throwError: $throwError);
    }

    /**
     * Execute pgBackRest using a temporary container.
     */
    private function executeInTempContainer(string $args, bool $withDataDir, bool $throwError): string
    {
        $mounts = $this->getMounts();

        if (empty($mounts['pgbackrest_config'])) {
            if ($throwError) {
                throw new \RuntimeException('pgBackRest config path could not be resolved.');
            }

            return '';
        }

        if ($this->hasLocalRepo() && empty($mounts['pgbackrest_repo'])) {
            if ($throwError) {
                throw new \RuntimeException('pgBackRest repository path could not be resolved for local repository.');
            }

            return '';
        }

        $image = $this->database->image;
        $volumeArgs = "-v {$mounts['pgbackrest_config']}:/etc/pgbackrest ";

        if ($this->hasLocalRepo()) {
            $volumeArgs .= "-v {$mounts['pgbackrest_repo']}:/var/lib/pgbackrest ";
        } else {
            $volumeArgs .= '-v pgbackrest-s3-logs-'.escapeshellarg($this->database->uuid).':/var/lib/pgbackrest ';
        }

        if ($withDataDir && ! empty($mounts['data_volume'])) {
            $volumeArgs = "-v {$mounts['data_volume']}:/var/lib/postgresql/data ".$volumeArgs;
        }

        $envArgs = '';
        if ($this->isS3Repo()) {
            $s3Vars = GeneratePgbackrestConfig::getS3EnvVars($this->database);
            foreach ($s3Vars as $key => $value) {
                if (! empty($value)) {
                    $envArgs .= ' -e '.escapeshellarg("{$key}={$value}");
                }
            }
        }

        $cmd = 'docker run --rm '.$envArgs.' '.$volumeArgs.
            "{$image} sh -c '".
            '(apk add --no-cache pgbackrest >/dev/null 2>&1 || (apt-get update >/dev/null 2>&1 && apt-get install -y pgbackrest >/dev/null 2>&1)); '.
            'mkdir -p /var/lib/pgbackrest/log /tmp/pgbackrest 2>/dev/null || true; '.
            'chown -R postgres:postgres /var/lib/pgbackrest /etc/pgbackrest 2>/dev/null || true; '.
            ($withDataDir ? 'chown -R postgres:postgres /var/lib/postgresql/data 2>/dev/null || true; ' : '').
            "su postgres -c \"pgbackrest {$args}\"".
            "' 2>&1";

        $fullCmd = "set +e; {$cmd}; EXIT_CODE=\$?; echo \"PGBACKREST_EXIT_CODE:\${EXIT_CODE}\"; exit \$EXIT_CODE";

        $output = instant_remote_process([$fullCmd], $this->server, throwError: false);

        $exitCode = 0;
        if (preg_match('/PGBACKREST_EXIT_CODE:(\d+)/', $output ?? '', $matches)) {
            $exitCode = (int) $matches[1];
            $output = preg_replace('/PGBACKREST_EXIT_CODE:\d+\s*$/', '', $output ?? '');
        }

        if ($exitCode !== 0 && $throwError) {
            $errorOutput = trim($output ?? '') ?: "pgBackRest command failed with exit code {$exitCode}";
            throw new \RuntimeException($errorOutput, $exitCode);
        }

        return $output ?? '';
    }

    /**
     * Resolve paths by checking filesystem when docker inspect fails.
     */
    private function resolvePathsFromFilesystem(string $containerName): array
    {
        $defaultConfig = $this->database->getPgbackrestConfigDir();
        $defaultRepo = $this->database->getPgbackrestRepoDir();

        $checkProdCmd = "test -d {$defaultRepo} && echo 'EXISTS' || echo 'MISSING'";
        $prodCheck = instant_remote_process([$checkProdCmd], $this->server, throwError: false);

        if (trim($prodCheck) === 'EXISTS') {
            return ['config' => $defaultConfig, 'repo' => $defaultRepo];
        }

        return ['config' => $defaultConfig, 'repo' => $defaultRepo];
    }

    /**
     * Format a pgBackRest error message for display.
     */
    public static function formatErrorMessage(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'FATAL')) {
            preg_match('/FATAL.*/', $message, $matches);
            if (! empty($matches)) {
                return "pgBackRest Error: {$matches[0]}";
            }
        }

        if (str_contains($message, 'ERROR')) {
            preg_match('/ERROR[^:]*:.*/', $message, $matches);
            if (! empty($matches)) {
                return "pgBackRest Error: {$matches[0]}";
            }
        }

        if (str_contains($message, 'database identifier') || str_contains($message, 'db-id')) {
            return 'Database identifier mismatch. Ensure you are restoring to an empty data directory matching the original cluster.';
        }

        if ((str_contains($message, 'S3') || str_contains($message, 's3')) && str_contains($message, 'ERROR')) {
            return 'Error accessing S3 repository. Check S3 endpoint, bucket, region, and credentials.';
        }

        if (str_contains($message, 'unable to load') && str_contains($message, 'configuration')) {
            return 'Unable to load S3 configuration. Verify network connectivity and S3 credentials.';
        }

        if (str_contains($message, 'archive_mode')) {
            return 'PostgreSQL is not configured for archiving. Please ensure pgBackRest is properly enabled and the database has been restarted.';
        }

        if (str_contains($message, 'stanza') || str_contains($message, 'could not find stanza')) {
            return 'pgBackRest stanza not found. The backup repository may not be initialized. Try creating a backup first.';
        }

        if (str_contains($message, 'backup set') || str_contains($message, 'backup not found')) {
            return 'Backup not found in the repository. It may have been expired by retention policy.';
        }

        if (str_contains($message, 'permission') || str_contains($message, 'Permission denied')) {
            return 'Permission error during restore. The pgBackRest container may not have proper access to the data directory.';
        }

        if (str_contains($message, 'connection refused') || str_contains($message, 'Connection refused')) {
            return 'Connection refused. Check network connectivity and ensure the target service is running.';
        }

        if (str_contains($message, 'timeout') || str_contains($message, 'Timeout')) {
            return 'Operation timed out. This may indicate network issues or a slow S3 connection.';
        }

        return strlen($message) > 500 ? substr($message, 0, 500).'...' : $message;
    }

    /**
     * Format backup type for display.
     */
    public static function formatBackupType(string $type): string
    {
        return match ($type) {
            'full' => 'Full',
            'diff' => 'Differential',
            'incr' => 'Incremental',
            default => ucfirst($type),
        };
    }

    /**
     * Get diagnostic information for debugging.
     */
    public function getDiagnostics(): array
    {
        $mounts = $this->getMounts();

        $repos = $this->database->pgbackrestRepos()
            ->with('s3Storage')
            ->get()
            ->map(fn ($repo) => [
                'index' => $repo->repo_index,
                'type' => $repo->type,
                'path' => $repo->path,
                's3_bucket' => $repo->s3Storage?->bucket,
                's3_endpoint' => $repo->s3Storage?->endpoint,
                's3_region' => $repo->s3Storage?->region,
            ])
            ->toArray();

        return [
            'database_uuid' => $this->database->uuid,
            'stanza' => $this->getStanzaName(),
            'repos' => $repos,
            'has_local_repo' => $this->hasLocalRepo(),
            'has_s3_repo' => $this->isS3Repo(),
            'mounts' => $mounts,
            'container_running' => $this->isContainerRunning(),
            'enabled' => $this->isEnabled(),
        ];
    }
}
