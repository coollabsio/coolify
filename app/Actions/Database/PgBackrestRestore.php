<?php

namespace App\Actions\Database;

use App\Jobs\PgBackrestRestoreJob;
use App\Models\DatabaseRestore;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\StandalonePostgresql;
use Lorisleiva\Actions\Concerns\AsAction;
use Visus\Cuid2\Cuid2;

class PgBackrestRestore
{
    use AsAction;

    public function handle(
        StandalonePostgresql $database,
        ?ScheduledDatabaseBackupExecution $execution = null,
        ?string $targetTime = null
    ): DatabaseRestore {
        $restore = DatabaseRestore::create([
            'uuid' => (string) new Cuid2,
            'database_id' => $database->id,
            'database_type' => $database->getMorphClass(),
            'engine' => 'pgbackrest',
            'scheduled_database_backup_execution_id' => $execution?->id,
            'target_label' => $execution?->pgbackrest_label,
            'target_time' => $targetTime,
            'status' => 'pending',
        ]);

        PgBackrestRestoreJob::dispatch($database, $restore, $execution, $targetTime);

        return $restore;
    }

    public function rules(): array
    {
        return [
            'database' => ['required'],
            'targetTime' => ['nullable', 'date'],
        ];
    }
}
