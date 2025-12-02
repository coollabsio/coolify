<?php

namespace App\Services;

use App\Models\Server;
use App\Models\StandalonePostgresql;
use Illuminate\Support\Collection;

/**
 * Centralized service for all pgBackRest operations.
 *
 * Handles backup listing, validation, restore, and delete operations.
 * Works whether the PostgreSQL container is running or stopped by
 * automatically switching between main container exec and temp container.
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

        if (preg_match('/PGBACKREST_CONFIG=([^\s]+)/', $output ?? '', $matches)) {
            $pgbackrestConfig = $matches[1];
        }
        if (preg_match('/PGBACKREST_REPO=([^\s]+)/', $output ?? '', $matches)) {
            $pgbackrestRepo = $matches[1];
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
            return ['deletable' => false, 'reason' => 'Backup not found in repository'];
        }

        $dependents = $backups->filter(fn ($b) => ($b['prior'] ?? null) === $label);

        if ($dependents->isNotEmpty()) {
            $dependentLabels = $dependents->pluck('label')->join(', ');

            return [
                'deletable' => false,
                'reason' => "This backup has dependent backups that would become unrestorable: {$dependentLabels}",
                'dependents' => $dependents->pluck('label')->toArray(),
            ];
        }

        return ['deletable' => true, 'reason' => null];
    }

    /**
     * Delete a backup from the repository.
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
     * Validate that a restore operation can proceed.
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
        $clearCmd = "docker run --rm -v {$dataVolume}:/var/lib/postgresql/data alpine sh -c 'rm -rf /var/lib/postgresql/data/* /var/lib/postgresql/data/.*' 2>/dev/null || true";
        instant_remote_process([$clearCmd], $this->server, throwError: false);
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
            $command .= ' --repo1-path=/var/lib/pgbackrest';
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

        if (empty($mounts['pgbackrest_config']) || empty($mounts['pgbackrest_repo'])) {
            if ($throwError) {
                throw new \RuntimeException('pgBackRest config/repository paths could not be resolved.');
            }

            return '';
        }

        $image = $this->database->image;
        $volumeArgs = "-v {$mounts['pgbackrest_config']}:/etc/pgbackrest ".
                      "-v {$mounts['pgbackrest_repo']}:/var/lib/pgbackrest ";

        if ($withDataDir && ! empty($mounts['data_volume'])) {
            $volumeArgs = "-v {$mounts['data_volume']}:/var/lib/postgresql/data ".$volumeArgs;
        }

        $cmd = 'docker run --rm '.$volumeArgs.
            "{$image} sh -c '".
            'apk add --no-cache pgbackrest 2>/dev/null || (apt-get update && apt-get install -y pgbackrest) 2>/dev/null; '.
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
        $configDir = database_configuration_dir()."/{$containerName}";
        $defaultConfig = "{$configDir}/pgbackrest";
        $defaultRepo = "{$configDir}/pgbackrest-repo";

        $checkProdCmd = "test -d {$defaultRepo} && echo 'EXISTS' || echo 'MISSING'";
        $prodCheck = instant_remote_process([$checkProdCmd], $this->server, throwError: false);

        if (trim($prodCheck) === 'EXISTS') {
            return ['config' => $defaultConfig, 'repo' => $defaultRepo];
        }

        $devConfigDir = "/var/lib/docker/volumes/coolify_dev_coolify_data/_data/databases/{$containerName}";
        $devCheckCmd = "test -d {$devConfigDir}/pgbackrest-repo && echo 'EXISTS' || echo 'MISSING'";
        $devCheck = instant_remote_process([$devCheckCmd], $this->server, throwError: false);

        if (trim($devCheck) === 'EXISTS') {
            return ['config' => "{$devConfigDir}/pgbackrest", 'repo' => "{$devConfigDir}/pgbackrest-repo"];
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

        if (str_contains($message, 'archive_mode')) {
            return 'PostgreSQL is not configured for archiving. Please ensure pgBackRest is properly enabled and the database has been restarted.';
        }

        if (str_contains($message, 'stanza')) {
            return 'pgBackRest stanza not found. The backup repository may not be initialized. Try creating a backup first.';
        }

        if (str_contains($message, 'backup set')) {
            return 'Backup not found in the repository. It may have been expired by retention policy.';
        }

        if (str_contains($message, 'permission')) {
            return 'Permission error during restore. The pgBackRest container may not have proper access to the data directory.';
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
}
