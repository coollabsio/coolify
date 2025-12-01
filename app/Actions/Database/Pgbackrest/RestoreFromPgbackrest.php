<?php

namespace App\Actions\Database\Pgbackrest;

use App\Models\StandalonePostgresql;
use Lorisleiva\Actions\Concerns\AsAction;

class RestoreFromPgbackrest
{
    use AsAction;

    public function handle(
        StandalonePostgresql $database,
        ?string $backupLabel = null,
        ?string $targetTime = null,
        ?string $targetDatabase = null
    ): array {
        if (! $database->isPgbackrestEnabled()) {
            return ['success' => false, 'message' => 'pgBackRest is not enabled'];
        }

        $containerName = $database->uuid;
        $stanzaName = $database->getPgbackrestStanzaName();

        $restoreCommand = $this->buildRestoreCommand($stanzaName, $backupLabel, $targetTime, $targetDatabase);

        return [
            'success' => true,
            'command' => $restoreCommand,
            'container' => $containerName,
            'stanza' => $stanzaName,
        ];
    }

    private function buildRestoreCommand(
        string $stanzaName,
        ?string $backupLabel = null,
        ?string $targetTime = null,
        ?string $targetDatabase = null
    ): string {
        $command = 'pgbackrest --stanza='.escapeshellarg($stanzaName);

        if ($backupLabel) {
            $command .= ' --set='.escapeshellarg($backupLabel);
        }

        if ($targetTime) {
            $command .= ' --type=time --target='.escapeshellarg($targetTime);
        } else {
            $command .= ' --type=immediate';
        }

        if ($targetDatabase) {
            $command .= ' --db-include='.escapeshellarg($targetDatabase);
        }

        $command .= ' --target-action=promote --delta restore';

        return $command;
    }

    public function getAvailableBackups(StandalonePostgresql $database): array
    {
        if (! $database->isPgbackrestEnabled()) {
            return ['success' => false, 'message' => 'pgBackRest is not enabled', 'backups' => []];
        }

        if (! isPostgresContainerRunning($database)) {
            return ['success' => false, 'message' => 'PostgreSQL container is not running', 'backups' => []];
        }

        $backups = getPgbackrestBackupList($database)->toArray();

        return ['success' => true, 'backups' => $backups];
    }

    public function validateRestore(StandalonePostgresql $database, ?string $backupLabel = null): array
    {
        if (! $database->isPgbackrestEnabled()) {
            return ['valid' => false, 'message' => 'pgBackRest is not enabled'];
        }

        if ($backupLabel) {
            $backup = getPgbackrestBackupByLabel($database, $backupLabel);
            if (! $backup) {
                return ['valid' => false, 'message' => 'Backup not found in pgBackRest repository'];
            }
        }

        return ['valid' => true, 'message' => 'Restore can proceed'];
    }
}
