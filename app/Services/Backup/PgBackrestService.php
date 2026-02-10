<?php

namespace App\Services\Backup;

use App\Models\PgbackrestRepo;
use App\Models\ScheduledDatabaseBackup;
use App\Models\StandalonePostgresql;

class PgBackrestService
{
    public const PGDATA_PATH = '/var/lib/postgresql/data';

    public const REPO_PATH = '/var/lib/pgbackrest';

    public const CONFIG_PATH = '/etc/pgbackrest';

    public const DEFAULT_COMPRESS_TYPE = 'lz4';

    public const DEFAULT_COMPRESS_LEVEL = 6;

    public const DEFAULT_LOG_LEVEL = 'info';

    public static function getStanzaName(StandalonePostgresql $database): string
    {
        return $database->uuid;
    }

    public static function generateConfig(StandalonePostgresql $database): ?string
    {
        $pgbackrestBackups = $database->pgbackrestBackups()->where('enabled', true)->get();

        if ($pgbackrestBackups->isEmpty()) {
            return null;
        }

        $backup = $pgbackrestBackups->first();
        $stanza = self::getStanzaName($database);

        $repos = $backup->enabledPgbackrestRepos()->get();
        if ($repos->isEmpty()) {
            return null;
        }

        $config = "[global]\n";
        $config .= 'log-level-console=' . ($backup->pgbackrest_log_level ?? self::DEFAULT_LOG_LEVEL) . "\n";
        $config .= 'log-level-file=' . ($backup->pgbackrest_log_level ?? self::DEFAULT_LOG_LEVEL) . "\n";
        $config .= 'compress-type=' . ($backup->pgbackrest_compress_type ?? self::DEFAULT_COMPRESS_TYPE) . "\n";
        $config .= 'compress-level=' . ($backup->pgbackrest_compress_level ?? self::DEFAULT_COMPRESS_LEVEL) . "\n";
        $config .= "start-fast=y\n";
        $config .= "stop-auto=y\n";
        $config .= "delta=y\n";
        $config .= "process-max=2\n";

        if ($backup->pgbackrest_archive_mode === 'minimal') {
            $config .= "archive-check=n\n";
        }

        $config .= "\n[{$stanza}]\n";
        $config .= 'pg1-path=' . self::PGDATA_PATH . "\n";

        $validRepoCount = 0;
        foreach ($repos as $repo) {
            $repoConfig = self::generateRepoConfig($repo, $database);
            if (!empty($repoConfig)) {
                $config .= $repoConfig;
                $validRepoCount++;
            }
        }

        if ($validRepoCount === 0) {
            return null;
        }

        return $config;
    }

    public static function generateRepoConfig(PgbackrestRepo $repo, StandalonePostgresql $database): string
    {
        $repoKey = $repo->getRepoKey();
        $settings = [];

        if ($repo->isS3()) {
            $s3 = $repo->s3Storage;
            if (!$s3) {
                return '';
            }

            try {
                validateShellSafePath($s3->bucket, 'S3 bucket');
                validateShellSafePath($s3->endpoint, 'S3 endpoint');
            } catch (\Exception $e) {
                throw new \Exception('Invalid S3 configuration: ' . $e->getMessage());
            }

            $settings = [
                "{$repoKey}-type" => 's3',
                "{$repoKey}-path" => "/{$database->uuid}",
                "{$repoKey}-s3-bucket" => $s3->bucket,
                "{$repoKey}-s3-endpoint" => self::cleanEndpoint($s3->endpoint),
                "{$repoKey}-s3-region" => $s3->region ?: 'us-east-1',
                "{$repoKey}-s3-uri-style" => 'path',
            ];
        } else {
            $repoPath = $repo->getEffectivePath();
            $settings = [
                "{$repoKey}-type" => 'posix',
                "{$repoKey}-path" => $repoPath,
            ];
        }

        $retentionFull = $repo->retention_full ?: 2;
        $retentionDiff = $repo->retention_diff ?: 7;
        $retentionFullType = $repo->retention_full_type === 'time' ? 'time' : 'count';

        $settings["{$repoKey}-retention-full-type"] = $retentionFullType;
        $settings["{$repoKey}-retention-full"] = $retentionFull;
        $settings["{$repoKey}-retention-diff"] = $retentionDiff;

        if ($repo->encryption_key) {
            $settings["{$repoKey}-cipher-type"] = 'aes-256-cbc';
            $settings["{$repoKey}-cipher-pass"] = $repo->encryption_key;
        }

        return implode("\n", array_map(fn($k, $v) => "{$k}={$v}", array_keys($settings), $settings)) . "\n";
    }

    public static function cleanEndpoint(string $endpoint): string
    {
        $endpoint = preg_replace('#^https?://#', '', $endpoint);
        $endpoint = rtrim($endpoint, '/');

        return $endpoint;
    }

    public static function buildSidecarBackupCommand(
        string $stanza,
        string $type,
        ScheduledDatabaseBackup $backup,
        string $containerName,
        string $network,
        string $volumeName
    ): string {
        $image = config('coolify.pgbackrest_image', 'pgbackrest/pgbackrest:latest');

        $envVars = self::buildS3EnvVars($backup);
        $dockerEnvArgs = self::buildDockerEnvArgs($envVars);

        // Mount points
        $configMount = "-v /tmp/pgbackrest-{$backup->uuid}.conf:/etc/pgbackrest/pgbackrest.conf:ro";
        // Mount the DB volume correctly. Assuming standard Postgres layout.
        // We use volumes-from to share the DB volume, or explicit volume mapping if we know the volume name.
        // For Sidecar pattern, --volumes-from is easiest if target container is running.
        // But if we want to be independent, mounting the named volume is better.
        // Let's use --volumes-from for now as it maps the paths automatically.
        $volumeMount = "--volumes-from {$containerName}:ro";

        $cmd = "pgbackrest --stanza={$stanza} --type={$type} backup";

        return "docker run --rm --network {$network} {$dockerEnvArgs} {$configMount} {$volumeMount} {$image} {$cmd}";
    }

    public static function buildSidecarRestoreCommand(
        string $stanza,
        ScheduledDatabaseBackup $backup, // We need backup config for env vars
        string $network,
        string $volumeMounts,
        string $targetTime = null
    ): string {
        $image = config('coolify.pgbackrest_image', 'pgbackrest/pgbackrest:latest');
        $envVars = self::buildS3EnvVars($backup);
        $dockerEnvArgs = self::buildDockerEnvArgs($envVars);

        $configMount = "-v /tmp/pgbackrest-{$backup->uuid}.conf:/etc/pgbackrest/pgbackrest.conf:ro";

        $cmd = "pgbackrest --stanza={$stanza} --delta restore";
        if ($targetTime) {
            $cmd .= " --type=time --target=\"{$targetTime}\" --target-action=promote";
        }

        return "docker run --rm --network {$network} {$dockerEnvArgs} {$configMount} {$volumeMounts} {$image} {$cmd}";
    }

    public static function buildSidecarInfoCommand(
        string $stanza,
        ScheduledDatabaseBackup $backup,
        string $network,
        string $containerName
    ): string {
        $image = config('coolify.pgbackrest_image', 'pgbackrest/pgbackrest:latest');
        $envVars = self::buildS3EnvVars($backup);
        $dockerEnvArgs = self::buildDockerEnvArgs($envVars);

        $configMount = "-v /tmp/pgbackrest-{$backup->uuid}.conf:/etc/pgbackrest/pgbackrest.conf:ro";
        // Info command might need access to local repo (volumes-from handles this)
        $volumeMount = "--volumes-from {$containerName}:ro";

        $cmd = "pgbackrest --stanza={$stanza} --output=json info";

        return "docker run --rm --network {$network} {$dockerEnvArgs} {$configMount} {$volumeMount} {$image} {$cmd}";
    }

    public static function buildS3EnvVars(ScheduledDatabaseBackup $backup): array
    {
        $envVars = [];
        $repos = $backup->enabledPgbackrestRepos()->get();

        foreach ($repos as $repo) {
            if ($repo->isS3() && $repo->s3Storage) {
                $s3 = $repo->s3Storage;
                $repoNum = $repo->repo_number;
                $envVars["PGBACKREST_REPO{$repoNum}_S3_KEY"] = $s3->key;
                $envVars["PGBACKREST_REPO{$repoNum}_S3_KEY_SECRET"] = $s3->secret;
            }
        }

        return $envVars;
    }

    public static function buildS3EnvVarsForRepo(PgbackrestRepo $repo): array
    {
        if (!$repo->isS3() || !$repo->s3Storage) {
            return [];
        }

        $s3 = $repo->s3Storage;
        $repoNum = $repo->repo_number;

        return [
            "PGBACKREST_REPO{$repoNum}_S3_KEY" => $s3->key,
            "PGBACKREST_REPO{$repoNum}_S3_KEY_SECRET" => $s3->secret,
        ];
    }

    public static function buildDockerEnvArgs(array $envVars): string
    {
        $args = '';
        foreach ($envVars as $key => $value) {
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                throw new \InvalidArgumentException("Invalid environment variable name: {$key}");
            }
            $args .= ' -e ' . escapeshellarg("{$key}={$value}");
        }

        return $args;
    }

    public static function buildBackupCommand(
        string $stanza,
        string $type = 'full',
        ?string $logLevel = null,
        ?int $repoNumber = null
    ): string {
        $escapedStanza = escapeshellarg($stanza);
        $cmd = "pgbackrest --stanza={$escapedStanza}";

        if ($logLevel) {
            $escapedLogLevel = escapeshellarg($logLevel);
            $cmd .= " --log-level-console={$escapedLogLevel}";
        }

        if ($repoNumber !== null) {
            $cmd .= ' --repo=' . ((int) $repoNumber);
        }

        $escapedType = escapeshellarg($type);
        $cmd .= " --type={$escapedType} backup";

        return $cmd;
    }

    public static function wrapWithLockWait(string $command, int $maxWaitSeconds = 900, int $intervalSeconds = 10): string
    {
        if ($intervalSeconds <= 0) {
            throw new \InvalidArgumentException('Interval seconds must be greater than 0');
        }
        if ($maxWaitSeconds <= 0) {
            throw new \InvalidArgumentException('Max wait seconds must be greater than 0');
        }

        $maxAttempts = (int) ceil($maxWaitSeconds / $intervalSeconds);

        return <<<BASH
attempt=0
max_attempts={$maxAttempts}
while [ \$attempt -lt \$max_attempts ]; do
    {$command}
    exit_code=\$?
    if [ \$exit_code -eq 0 ]; then
        exit 0
    elif [ \$exit_code -eq 50 ]; then
        echo "Lock held by another process, waiting {$intervalSeconds}s before retry (\$((attempt+1))/\$max_attempts)..."
        sleep {$intervalSeconds}
        attempt=\$((attempt+1))
    else
        exit \$exit_code
    fi
done
echo "ERROR: Timeout waiting for lock after {$maxWaitSeconds} seconds"
exit 50
BASH;
    }

    public static function buildRestoreCommand(
        string $stanza,
        ?string $label = null,
        ?string $targetTime = null,
        ?string $logLevel = null,
        ?int $repoNumber = null
    ): string {
        $escapedStanza = escapeshellarg($stanza);
        $cmd = "pgbackrest --stanza={$escapedStanza}";

        if ($logLevel) {
            $escapedLogLevel = escapeshellarg($logLevel);
            $cmd .= " --log-level-console={$escapedLogLevel}";
        }

        if ($repoNumber !== null) {
            $cmd .= ' --repo=' . ((int) $repoNumber);
        }

        if ($label) {
            $escapedLabel = escapeshellarg($label);
            $cmd .= " --set={$escapedLabel}";
        }

        if ($targetTime) {
            $escapedTargetTime = escapeshellarg($targetTime);
            $cmd .= " --type=time --target={$escapedTargetTime} --target-action=promote";
        }

        $cmd .= ' restore';

        return $cmd;
    }

    public static function buildInfoCommand(string $stanza, bool $json = true, ?int $repoNumber = null): string
    {
        $escapedStanza = escapeshellarg($stanza);
        $cmd = "pgbackrest --stanza={$escapedStanza}";

        if ($repoNumber !== null) {
            $cmd .= ' --repo=' . ((int) $repoNumber);
        }

        if ($json) {
            $cmd .= ' --output=json';
        }

        $cmd .= ' info';

        return $cmd;
    }

    public static function buildStanzaCreateCommand(string $stanza): string
    {
        $escapedStanza = escapeshellarg($stanza);

        return "pgbackrest --stanza={$escapedStanza} --log-level-console=info stanza-create";
    }

    public static function buildExpireCommand(
        string $stanza,
        ?int $repoNumber = null
    ): string {
        $escapedStanza = escapeshellarg($stanza);
        $cmd = "pgbackrest --stanza={$escapedStanza}";

        if ($repoNumber !== null) {
            $cmd .= ' --repo=' . ((int) $repoNumber);
        }

        $cmd .= ' expire';

        return $cmd;
    }

    public static function parseInfoJson(string $json): ?array
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    public static function getLatestBackup(array $info): ?array
    {
        if (empty($info) || !isset($info[0]['backup'])) {
            return null;
        }

        $backups = $info[0]['backup'] ?? [];

        if (empty($backups)) {
            return null;
        }

        return end($backups);
    }

    public static function findBackupByLabel(array $info, string $label): ?array
    {
        if (empty($info) || !isset($info[0]['backup'])) {
            return null;
        }

        foreach ($info[0]['backup'] as $backup) {
            if (($backup['label'] ?? '') === $label) {
                return $backup;
            }
        }

        return null;
    }

    public static function getBackupSize(array $backup): int
    {
        return $backup['info']['repository']['size'] ?? 0;
    }

    public static function getBackupType(array $backup): string
    {
        return $backup['type'] ?? 'full';
    }

    public static function stanzaExists(array $info): bool
    {
        if (empty($info) || !isset($info[0])) {
            return false;
        }

        $status = $info[0]['status'] ?? [];

        return ($status['code'] ?? 0) === 0;
    }

    public static function hasBackups(array $info): bool
    {
        if (empty($info) || !isset($info[0]['backup'])) {
            return false;
        }

        return count($info[0]['backup']) > 0;
    }
}
