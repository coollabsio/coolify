<?php

namespace App\Actions\Database\PgBackRest;

use App\Models\Server;
use App\Models\StandalonePostgresql;
use Lorisleiva\Actions\Concerns\AsAction;

class GetPgBackRestInfo
{
    use AsAction;

    public function handle(
        StandalonePostgresql $database,
        Server $server,
        string $containerName,
    ): array {
        $stanza = $database->pgbackrest_stanza ?? 'db-'.$database->uuid;

        // Check if pgBackRest is available
        $checkCmd = "docker exec {$containerName} which pgbackrest 2>/dev/null || echo 'NOT_FOUND'";
        $result = instant_remote_process([$checkCmd], $server, false, false, null, disableMultiplexing: true);

        if (str($result)->contains('NOT_FOUND')) {
            return [
                'installed' => false,
                'configured' => false,
                'stanza' => $stanza,
                'backups' => [],
                'wal_archiving' => false,
            ];
        }

        // Check if config exists
        $checkConf = "docker exec {$containerName} test -f /etc/pgbackrest/pgbackrest.conf && echo 'EXISTS' || echo 'NOT_FOUND'";
        $confResult = instant_remote_process([$checkConf], $server, false, false, null, disableMultiplexing: true);
        $configured = ! str($confResult)->contains('NOT_FOUND');

        // Get backup info
        $backups = [];
        if ($configured) {
            try {
                $cmd = "docker exec {$containerName} su - postgres -c \"pgbackrest info --stanza={$stanza} --output=json\" 2>/dev/null";
                $output = instant_remote_process([$cmd], $server, false, false, 30, disableMultiplexing: true);

                if (filled($output)) {
                    $parsed = json_decode(trim($output), true);
                    if (! empty($parsed)) {
                        $stanzaInfo = $parsed[0] ?? [];
                        $rawBackups = $stanzaInfo['backup'] ?? [];

                        foreach ($rawBackups as $backup) {
                            $backups[] = [
                                'label' => $backup['label'] ?? null,
                                'type' => $backup['type'] ?? null,
                                'timestamp_start' => isset($backup['timestamp']['start']) ? date('Y-m-d H:i:s', $backup['timestamp']['start']) : null,
                                'timestamp_stop' => isset($backup['timestamp']['stop']) ? date('Y-m-d H:i:s', $backup['timestamp']['stop']) : null,
                                'size' => $backup['info']['size'] ?? 0,
                                'delta_size' => $backup['info']['delta']['size'] ?? 0,
                                'repository_size' => $backup['info']['repository']['size'] ?? 0,
                                'repository_delta_size' => $backup['info']['repository']['delta']['size'] ?? 0,
                                'wal_start' => $backup['lsn']['start'] ?? null,
                                'wal_stop' => $backup['lsn']['stop'] ?? null,
                                'database_ref' => $backup['database']['ref'] ?? [],
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Info retrieval failed, return what we have
            }
        }

        // Check WAL archiving status
        $walArchiving = false;
        try {
            $walCmd = "docker exec {$containerName} su - postgres -c \"psql -tAc \\\"SHOW archive_mode;\\\"\"";
            $walResult = trim(instant_remote_process([$walCmd], $server, false, false, null, disableMultiplexing: true));
            $walArchiving = ($walResult === 'on');
        } catch (\Throwable $e) {
            // Ignore
        }

        return [
            'installed' => true,
            'configured' => $configured,
            'stanza' => $stanza,
            'backups' => $backups,
            'wal_archiving' => $walArchiving,
            'backup_count' => count($backups),
        ];
    }
}
