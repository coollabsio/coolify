<?php

namespace App\Actions\Database\PgBackRest;

use App\Models\Server;
use App\Models\StandalonePostgresql;
use Lorisleiva\Actions\Concerns\AsAction;

class RunPgBackRestBackup
{
    use AsAction;

    public function handle(
        StandalonePostgresql $database,
        Server $server,
        string $containerName,
        string $backupType = 'full',
        ?int $timeout = 3600,
    ): array {
        $stanza = $database->pgbackrest_stanza ?? 'db-'.$database->uuid;

        // Ensure pgBackRest is installed and configured
        $this->ensureSetup($server, $containerName, $stanza);

        // Run the backup
        $output = $this->runBackup($server, $containerName, $stanza, $backupType, $timeout);

        // Get backup info
        $info = $this->getBackupInfo($server, $containerName, $stanza);

        // Parse the latest backup's size
        $size = $this->parseBackupSize($info);
        $location = "pgbackrest://{$stanza}/{$backupType}";

        return [
            'output' => $output,
            'location' => $location,
            'size' => $size,
            'info' => $info,
            'type' => $backupType,
            'stanza' => $stanza,
        ];
    }

    private function ensureSetup(Server $server, string $containerName, string $stanza): void
    {
        // Check if pgBackRest is installed
        $checkCmd = "docker exec {$containerName} which pgbackrest 2>/dev/null || echo 'NOT_FOUND'";
        $result = instant_remote_process([$checkCmd], $server, false, false, null, disableMultiplexing: true);

        if (str($result)->contains('NOT_FOUND')) {
            throw new \RuntimeException(
                'pgBackRest is not installed in the container. Please run setup first via the database configuration page or API.'
            );
        }

        // Verify stanza exists by checking pgbackrest.conf
        $checkConf = "docker exec {$containerName} test -f /etc/pgbackrest/pgbackrest.conf && echo 'EXISTS' || echo 'NOT_FOUND'";
        $result = instant_remote_process([$checkConf], $server, false, false, null, disableMultiplexing: true);

        if (str($result)->contains('NOT_FOUND')) {
            throw new \RuntimeException(
                'pgBackRest configuration not found. Please run setup first.'
            );
        }
    }

    private function runBackup(Server $server, string $containerName, string $stanza, string $backupType, ?int $timeout): string
    {
        $validTypes = ['full', 'diff', 'incr'];
        if (! in_array($backupType, $validTypes)) {
            throw new \InvalidArgumentException("Invalid backup type: {$backupType}. Must be one of: ".implode(', ', $validTypes));
        }

        $cmd = "docker exec {$containerName} su - postgres -c \"pgbackrest backup --stanza={$stanza} --type={$backupType} --compress-type=lz4\"";
        $output = instant_remote_process([$cmd], $server, true, false, $timeout, disableMultiplexing: true);

        return trim($output ?? '');
    }

    private function getBackupInfo(Server $server, string $containerName, string $stanza): ?array
    {
        $cmd = "docker exec {$containerName} su - postgres -c \"pgbackrest info --stanza={$stanza} --output=json\"";
        $output = instant_remote_process([$cmd], $server, false, false, 30, disableMultiplexing: true);

        if (blank($output)) {
            return null;
        }

        $parsed = json_decode(trim($output), true);

        return $parsed;
    }

    private function parseBackupSize(?array $info): int
    {
        if (empty($info)) {
            return 0;
        }

        // pgBackRest info JSON returns an array of stanzas
        // Each stanza has a 'backup' array with individual backups
        $stanzaInfo = $info[0] ?? [];
        $backups = $stanzaInfo['backup'] ?? [];

        if (empty($backups)) {
            return 0;
        }

        // Get the latest backup (last in the array)
        $latestBackup = end($backups);
        $dbInfo = $latestBackup['info'] ?? [];
        $size = $dbInfo['size'] ?? 0;

        return (int) $size;
    }
}
