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

        $retentionFull = $database->pgbackrest_retention_full ?? config('constants.pgbackrest.default_retention_full', 2);
        $retentionDiff = $database->pgbackrest_retention_diff ?? config('constants.pgbackrest.default_retention_diff', 7);
        $compressType = $database->pgbackrest_compress_type ?? config('constants.pgbackrest.default_compress_type', 'lz4');
        $compressLevel = $database->pgbackrest_compress_level ?? config('constants.pgbackrest.default_compress_level', 6);
        $logLevel = $database->pgbackrest_log_level ?? config('constants.pgbackrest.default_log_level', 'info');

        $config = [];

        $config[] = '[global]';
        $config[] = 'repo1-path=/var/lib/pgbackrest';
        $config[] = "repo1-retention-full={$retentionFull}";
        $config[] = "repo1-retention-diff={$retentionDiff}";
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
}
