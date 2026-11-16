<?php

namespace App\Actions\Node;

use App\Jobs\DatabaseBackupJob;
use App\Models\NodeWorkload;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * The dump backups of a cluster database to S3, for the Backups page and the API (decision
 * 0029). The caller authorizes `update` on the database. An S3 storage that no backup schedule of
 * the database uses yet is a team credential (`useTeamCredentials`).
 */
class ManageNodeDatabaseBackups
{
    use AsAction;

    /**
     * @param  string  $source  `ui` or `api`, for the audit log.
     *
     * @throws ValidationException `frequency` or `s3_storage`.
     */
    public function createSchedule(NodeWorkload $workload, string $frequency, ?S3Storage $s3, User $user, string $source = 'ui'): ScheduledDatabaseBackup
    {
        if ($workload->database_type?->dumpEngine() === null) {
            throw new RuntimeException('Dump backups are not available for this database.');
        }
        if (! validate_cron_expression($frequency)) {
            throw ValidationException::withMessages(['frequency' => 'The frequency must be a valid cron or human expression.']);
        }
        if ($s3 === null || ! self::storages($workload)->whereKey($s3->id)->exists()) {
            throw ValidationException::withMessages(['s3_storage' => 'Select a usable S3 storage of the team.']);
        }
        self::authorizeStorage($workload, $s3, $user);
        $key = $workload->database_type->backupDatabaseKey();

        $backup = ScheduledDatabaseBackup::query()->create([
            'team_id' => $workload->team_id,
            'database_type' => $workload->getMorphClass(),
            'database_id' => $workload->id,
            'frequency' => $frequency,
            'enabled' => true,
            'save_s3' => true,
            'disable_local_backup' => true,
            's3_storage_id' => $s3->id,
            'databases_to_backup' => $key === null ? null : $workload->environment_variables()->where('key', $key)->value('value'),
            'dump_all' => false,
            'database_backup_retention_amount_locally' => 0,
            'database_backup_retention_days_locally' => 0,
            'database_backup_retention_max_storage_locally' => 0,
            'database_backup_retention_amount_s3' => 7,
        ]);
        $backup->syncS3Storages([$s3->id]);
        auditLog("{$source}.database.backup_schedule_created", [
            'team_id' => $workload->team_id,
            'database_uuid' => $workload->uuid,
            'database_name' => $workload->name,
            'backup_uuid' => $backup->uuid,
            'frequency' => $backup->frequency,
            'save_s3' => true,
        ]);

        return $backup;
    }

    /**
     * Saves the settings of a backup schedule, like the schedule page. `s3_storage` is the selected
     * storage of the team; the current one stays selectable while it is unusable.
     *
     * @param  array{frequency: string, enabled?: bool, databases_to_backup: ?string, dump_all: bool, s3_storage: ?S3Storage, database_backup_retention_amount_s3: int, database_backup_retention_days_s3: int, database_backup_retention_max_storage_s3: float|int|string, timeout: int, missing_backup_notification_days: int}  $input
     *
     * @throws ValidationException With the snake_case field names, and `s3_storage`.
     */
    public function updateSchedule(NodeWorkload $workload, ScheduledDatabaseBackup $backup, array $input, User $user, string $source = 'ui'): ScheduledDatabaseBackup
    {
        Validator::make($input, self::scheduleRules(), self::scheduleMessages())->validate();
        if (! validate_cron_expression($input['frequency'])) {
            throw ValidationException::withMessages(['frequency' => 'The frequency must be a valid cron or human expression.']);
        }
        $s3 = $input['s3_storage'] ?? null;
        if ($s3 === null || ! self::storages($workload, $backup->s3_storage_id)->whereKey($s3->id)->exists()) {
            throw ValidationException::withMessages(['s3_storage' => 'Select a usable S3 storage of the team.']);
        }
        self::authorizeStorage($workload, $s3, $user);

        $backup->fill([
            'frequency' => $input['frequency'],
            ...(array_key_exists('enabled', $input) ? ['enabled' => (bool) $input['enabled']] : []),
            'databases_to_backup' => filled($input['databases_to_backup'] ?? null) ? $input['databases_to_backup'] : null,
            'dump_all' => (bool) $input['dump_all'],
            's3_storage_id' => $s3->id,
            'database_backup_retention_amount_s3' => $input['database_backup_retention_amount_s3'],
            'database_backup_retention_days_s3' => $input['database_backup_retention_days_s3'],
            'database_backup_retention_max_storage_s3' => $input['database_backup_retention_max_storage_s3'],
            'timeout' => $input['timeout'],
            'missing_backup_notification_days' => $input['missing_backup_notification_days'],
        ]);
        $changedFields = auditChangedFields($backup);
        $backup->save();
        $backup->syncS3Storages([$s3->id]);
        if ($changedFields !== []) {
            auditLog("{$source}.database.backup_schedule_updated", [
                'team_id' => $workload->team_id,
                'database_uuid' => $workload->uuid,
                'backup_uuid' => $backup->uuid,
                'changed_fields' => $changedFields,
            ]);
        }

        return $backup;
    }

    /** Deletes a backup schedule and its runs. With `$deleteS3`, also the files of the runs in S3. */
    public function deleteSchedule(NodeWorkload $workload, ScheduledDatabaseBackup $backup, bool $deleteS3, string $source = 'ui'): void
    {
        if ($deleteS3) {
            $backup->executions()->get()->each(fn (ScheduledDatabaseBackupExecution $execution) => $execution->deleteS3Copies());
        }
        $backup->executions()->delete();
        $backup->delete();
        auditLog("{$source}.database.backup_schedule_deleted", [
            'team_id' => $workload->team_id,
            'database_uuid' => $workload->uuid,
            'database_name' => $workload->name,
            'backup_uuid' => $backup->uuid,
        ]);
    }

    /** @return array<string, list<string>> The rules of the schedule settings, with snake_case names. */
    public static function scheduleRules(): array
    {
        return [
            'frequency' => ['required', 'string'],
            'enabled' => ['sometimes', 'boolean'],
            'databases_to_backup' => ['nullable', 'string', 'max:2000', 'regex:/^[A-Za-z0-9_][A-Za-z0-9_-]{0,63}(\s*,\s*[A-Za-z0-9_][A-Za-z0-9_-]{0,63})*$/'],
            'dump_all' => ['required', 'boolean'],
            'database_backup_retention_amount_s3' => ['required', 'integer', 'min:0', 'max:10000'],
            'database_backup_retention_days_s3' => ['required', 'integer', 'min:0'],
            'database_backup_retention_max_storage_s3' => ['required', 'numeric', 'min:0'],
            'timeout' => ['required', 'integer', 'min:60', 'max:36000'],
            'missing_backup_notification_days' => ['required', 'integer', 'min:0', 'max:365'],
        ];
    }

    /** @return array<string, string> */
    public static function scheduleMessages(): array
    {
        return ['databases_to_backup.regex' => 'Enter database names separated by commas. A name has letters, digits, `_`, and `-`.'];
    }

    /** Queues a backup now. It fails at once when the server of the database or the S3 storage cannot take it. */
    public function runNow(NodeWorkload $workload, ScheduledDatabaseBackup $backup, string $source = 'ui'): void
    {
        DumpNodeDatabase::node(DumpNodeDatabase::workload($backup));
        DumpNodeDatabase::storage($backup, $workload);
        DatabaseBackupJob::dispatch($backup);
        auditLog("{$source}.database.backup_started", [
            'team_id' => $workload->team_id,
            'database_uuid' => $workload->uuid,
            'database_name' => $workload->name,
            'backup_uuid' => $backup->uuid,
        ]);
    }

    /** @return Builder<ScheduledDatabaseBackup> */
    public static function schedulesOf(NodeWorkload $workload): Builder
    {
        return ScheduledDatabaseBackup::query()
            ->where('database_type', $workload->getMorphClass())
            ->where('database_id', $workload->id);
    }

    /**
     * Usable S3 storages of the team of the database, plus the current one.
     *
     * @return Builder<S3Storage>
     */
    public static function storages(NodeWorkload $workload, ?int $currentId = null): Builder
    {
        return S3Storage::query()
            ->where('team_id', $workload->team_id)
            ->where(fn (Builder $query) => $query->where('is_usable', true)->when($currentId !== null, fn (Builder $query) => $query->orWhere('id', $currentId)));
    }

    public static function authorizeStorage(NodeWorkload $workload, S3Storage $s3, User $user): void
    {
        if (! self::schedulesOf($workload)->where('s3_storage_id', $s3->id)->exists()) {
            Gate::forUser($user)->authorize('useTeamCredentials', $workload);
        }
    }
}
