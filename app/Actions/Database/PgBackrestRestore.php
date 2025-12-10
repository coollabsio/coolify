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
        $attempts = 0;
        do {
            $uuid = (string) new Cuid2;
            $exists = DatabaseRestore::where('uuid', $uuid)->exists();
            $attempts++;
            if ($attempts >= 3 && $exists) {
                throw new \Exception('Unable to generate unique UUID for restore after 3 attempts');
            }
        } while ($exists);

        $restore = DatabaseRestore::create([
            'uuid' => $uuid,
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
        ];
    }
}
