<?php

namespace App\Actions\Database\Pgbackrest;

use App\Models\StandalonePostgresql;
use Lorisleiva\Actions\Concerns\AsAction;

class GeneratePgbackrestConfig
{
    use AsAction;

    public static function usesS3(StandalonePostgresql $database): bool
    {
        return $database->pgbackrestRepos()
            ->where('type', 's3')
            ->exists();
    }

    public static function hasLocalRepo(StandalonePostgresql $database): bool
    {
        return $database->pgbackrestRepos()
            ->where('type', 'posix')
            ->exists();
    }

    public function handle(StandalonePostgresql $database): string
    {
        $stanzaName = $database->getPgbackrestStanzaName();

        $compressType = $database->pgbackrest_compress_type
            ?? config('constants.pgbackrest.default_compress_type', 'lz4');
        $compressLevel = $database->pgbackrest_compress_level
            ?? config('constants.pgbackrest.default_compress_level', 6);
        $logLevel = $database->pgbackrest_log_level
            ?? config('constants.pgbackrest.default_log_level', 'info');

        $repos = $database->pgbackrestRepos()
            ->with('s3Storage')
            ->orderBy('repo_index')
            ->get();

        $config = [];
        $config[] = '[global]';

        foreach ($repos as $repo) {
            $idx = $repo->repo_index;

            if ($repo->type === 'posix') {
                $config[] = "repo{$idx}-path={$repo->path}";
            } elseif ($repo->type === 's3' && $repo->s3Storage) {
                $config[] = "repo{$idx}-type=s3";
                $config[] = "repo{$idx}-path={$repo->path}";
                $config[] = "repo{$idx}-s3-bucket={$repo->s3Storage->bucket}";
                $config[] = "repo{$idx}-s3-endpoint={$repo->s3Storage->endpoint}";
                $config[] = "repo{$idx}-s3-region={$repo->s3Storage->region}";
                $config[] = "repo{$idx}-s3-uri-style=path";
            }

            $retentionFull = $repo->retention_full_effective;
            $retentionDiff = $repo->retention_diff_effective;
            $retentionFullType = $repo->retention_full_type_effective;
            $retentionArchive = $repo->retention_archive_effective;
            $retentionArchiveType = $repo->retention_archive_type_effective;

            $config[] = "repo{$idx}-retention-full-type={$retentionFullType}";
            $config[] = "repo{$idx}-retention-full={$retentionFull}";
            $config[] = "repo{$idx}-retention-diff={$retentionDiff}";
            $config[] = "repo{$idx}-retention-archive-type={$retentionArchiveType}";
            if ($retentionArchive !== null) {
                $config[] = "repo{$idx}-retention-archive={$retentionArchive}";
            }
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

    public static function isS3ConfigComplete(StandalonePostgresql $database): bool
    {
        $s3Repos = $database->pgbackrestRepos()
            ->where('type', 's3')
            ->with('s3Storage')
            ->get();

        foreach ($s3Repos as $repo) {
            if (! $repo->s3Storage || ! $repo->s3Storage->isUsable()) {
                return false;
            }
        }

        return true;
    }

    public static function getS3EnvVars(StandalonePostgresql $database): array
    {
        $vars = [];

        $s3Repos = $database->pgbackrestRepos()
            ->where('type', 's3')
            ->with('s3Storage')
            ->get();

        foreach ($s3Repos as $repo) {
            if (! $repo->s3Storage || ! $repo->s3Storage->isUsable()) {
                continue;
            }

            $idx = $repo->repo_index;
            $vars["PGBACKREST_REPO{$idx}_S3_KEY"] = $repo->s3Storage->key;
            $vars["PGBACKREST_REPO{$idx}_S3_KEY_SECRET"] = $repo->s3Storage->secret;
        }

        return $vars;
    }
}
