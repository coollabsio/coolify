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
        $config[] = 'start-fast=y';
        $config[] = 'stop-auto=y';
        $config[] = 'delta=y';
        $config[] = 'process-max=2';

        $config[] = '';
        $config[] = "[{$stanzaName}]";
        // Access PostgreSQL data directly via mounted volume (no remote connection needed)
        // The pgbackrest container mounts the postgres data volume at /var/lib/postgresql/data
        $config[] = 'pg1-path=/var/lib/postgresql/data';

        return implode("\n", $config);
    }

    public function generatePostgresConfig(StandalonePostgresql $database): array
    {
        $stanzaName = $database->getPgbackrestStanzaName();
        $pgbackrestContainer = $database->getPgbackrestContainerName();

        return [
            'archive_mode' => 'on',
            'archive_command' => "pgbackrest --stanza={$stanzaName} --pg1-host={$pgbackrestContainer} archive-push %p",
            'archive_timeout' => '60',
        ];
    }
}
