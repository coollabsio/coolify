<?php

namespace App\Actions\Database\Pgbackrest;

use App\Models\StandalonePostgresql;
use App\Services\PgbackrestService;
use Lorisleiva\Actions\Concerns\AsAction;

class RestoreFromPgbackrest
{
    use AsAction;

    public function getAvailableBackups(StandalonePostgresql $database): array
    {
        $service = PgbackrestService::for($database);

        if (! $service->isEnabled()) {
            return ['success' => false, 'message' => 'pgBackRest is not enabled', 'backups' => []];
        }

        $backups = $service->getBackupList();

        if ($backups->isEmpty()) {
            return ['success' => true, 'message' => 'No backups found in pgBackRest repository', 'backups' => []];
        }

        return ['success' => true, 'backups' => $backups->toArray()];
    }

    public function validateRestore(StandalonePostgresql $database, ?string $backupLabel = null): array
    {
        return PgbackrestService::for($database)->validateRestore($backupLabel);
    }
}
