<?php

namespace App\Actions\Database\Pgbackrest;

use App\Models\StandalonePostgresql;
use Lorisleiva\Actions\Concerns\AsAction;

class GeneratePgbackrestConfig
{
    use AsAction;

    public function handle(StandalonePostgresql $database): string
    {
        $stanzaName = $database->getPgbackrestStanzaName();
        $repoType = $database->pgbackrest_repo_type ?? 'posix';

        $retentionFull = $database->pgbackrest_retention_full ?? config('constants.pgbackrest.default_retention_full', 2);
        $retentionDiff = $database->pgbackrest_retention_diff ?? config('constants.pgbackrest.default_retention_diff', 7);
        $retentionFullType = $database->pgbackrest_retention_full_type ?? 'count';
        $retentionArchive = $database->pgbackrest_retention_archive ?? null;
        $retentionArchiveType = $database->pgbackrest_retention_archive_type ?? 'full';
        $compressType = $database->pgbackrest_compress_type ?? config('constants.pgbackrest.default_compress_type', 'lz4');
        $compressLevel = $database->pgbackrest_compress_level ?? config('constants.pgbackrest.default_compress_level', 6);
        $logLevel = $database->pgbackrest_log_level ?? config('constants.pgbackrest.default_log_level', 'info');

        $config = [];

        $config[] = '[global]';

        if ($repoType === 's3') {
            $config[] = 'repo1-type=s3';
            $config[] = "repo1-path=/coolify/{$database->uuid}";
            $config[] = "repo1-s3-bucket={$database->pgbackrest_s3_bucket}";
            $config[] = "repo1-s3-endpoint={$database->pgbackrest_s3_endpoint}";
            $config[] = "repo1-s3-region={$database->pgbackrest_s3_region}";
            $config[] = 'repo1-s3-uri-style='.($database->pgbackrest_s3_uri_style ?? 'path');
            $config[] = 'repo1-s3-verify-tls='.($database->pgbackrest_s3_verify_tls ? 'y' : 'n');
        } else {
            $config[] = 'repo1-path=/var/lib/pgbackrest';
        }

        $config[] = "repo1-retention-full-type={$retentionFullType}";
        $config[] = "repo1-retention-full={$retentionFull}";
        $config[] = "repo1-retention-diff={$retentionDiff}";
        $config[] = "repo1-retention-archive-type={$retentionArchiveType}";
        if ($retentionArchive !== null) {
            $config[] = "repo1-retention-archive={$retentionArchive}";
        }
        $config[] = "compress-type={$compressType}";
        $config[] = "compress-level={$compressLevel}";
        $config[] = "log-level-console={$logLevel}";
        $config[] = 'log-level-file=detail';
        $config[] = 'log-path=/var/lib/pgbackrest/log';
        $config[] = 'lock-path=/tmp/pgbackrest';
        $config[] = 'start-fast=y';
        $config[] = 'stop-auto=y';
        $config[] = 'delta=y';
        $config[] = 'process-max=2';

        $config[] = '';
        $config[] = "[{$stanzaName}]";
        $config[] = 'pg1-path=/var/lib/postgresql/data';
        $config[] = 'pg1-socket-path=/var/run/postgresql';
        $config[] = 'pg1-port=5432';
        $config[] = "pg1-user={$database->postgres_user}";
        $config[] = "pg1-database={$database->postgres_db}";

        return implode("\n", $config);
    }

    public function generatePostgresConfig(StandalonePostgresql $database): array
    {
        $stanzaName = $database->getPgbackrestStanzaName();

        return [
            'wal_level' => 'replica',
            'archive_mode' => 'on',
            'archive_command' => "pgbackrest --stanza={$stanzaName} archive-push %p",
            'archive_timeout' => '7200',
        ];
    }

    /**
     * Check if S3 configuration is complete.
     */
    public static function isS3ConfigComplete(StandalonePostgresql $database): bool
    {
        if (($database->pgbackrest_repo_type ?? 'posix') !== 's3') {
            return true;
        }

        return ! empty($database->pgbackrest_s3_bucket)
            && ! empty($database->pgbackrest_s3_endpoint)
            && ! empty($database->pgbackrest_s3_region)
            && ! empty($database->pgbackrest_s3_key)
            && ! empty($database->pgbackrest_s3_secret);
    }

    /**
     * Get S3 credential environment variables for container use.
     */
    public static function getS3EnvVars(StandalonePostgresql $database): array
    {
        if (($database->pgbackrest_repo_type ?? 'posix') !== 's3') {
            return [];
        }

        return [
            'PGBACKREST_REPO1_S3_KEY' => $database->pgbackrest_s3_key,
            'PGBACKREST_REPO1_S3_KEY_SECRET' => $database->pgbackrest_s3_secret,
        ];
    }
}
