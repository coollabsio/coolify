<?php

namespace App\Actions\Database\Pgbackrest;

use App\Models\StandalonePostgresql;
use Lorisleiva\Actions\Concerns\AsAction;

class RestoreFromPgbackrest
{
    use AsAction;

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
