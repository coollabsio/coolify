<?php

namespace App\Actions\Node;

use App\Jobs\RestoreNodeDatabaseJob;
use App\Models\NodeWorkload;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Restores a dump of a cluster database (decision 0010). Sentinel downloads the dump with a
 * presigned URL, checks its SHA-256, and streams it into the fixed restore command of the engine
 * in the running container. The state of the latest restore of each database is in the cache, so
 * the backups page can show it.
 */
class RestoreNodeDatabase
{
    use AsAction;

    public const int POLL_SECONDS = 2;

    /** How long the backups page shows a finished restore. */
    public const int STATE_HOURS = 24;

    /** Checks the execution and queues its restore. */
    public static function queue(NodeWorkload $workload, ScheduledDatabaseBackupExecution $execution, User $requestedBy): void
    {
        self::check($workload, $execution);
        $state = self::state($workload);
        if (in_array($state['state'] ?? null, ['queued', 'running'], true)) {
            throw new RuntimeException('A restore of this database is already running.');
        }
        self::record($workload, [
            'state' => 'queued',
            'execution_uuid' => $execution->uuid,
            'database' => $execution->database_name,
            'requested_by' => $requestedBy->id,
            'bytes_done' => 0,
            'bytes_total' => (int) $execution->size,
            'error' => '',
        ]);
        RestoreNodeDatabaseJob::dispatch($workload->id, $execution->id);
    }

    public function handle(NodeWorkload $workload, ScheduledDatabaseBackupExecution $execution, Carbon $deadline): void
    {
        $backup = self::check($workload, $execution);
        $node = DumpNodeDatabase::node($workload);
        $s3 = self::storage($execution, $workload);
        $restoreId = $execution->uuid.'-'.Str::lower(Str::random(8));
        $request = [
            'server_id' => $node->uuid,
            'restore_id' => $restoreId,
            'container_name' => $workload->containerNameOn($node),
            'engine' => $workload->database_type->dumpEngine(),
            'database' => DumpNodeDatabase::requestDatabase($execution),
            'sha256' => (string) $execution->sha256,
            'archive_bytes' => (int) $execution->size,
        ];
        $connectionErrors = 0;
        while (true) {
            if (now()->gte($deadline)) {
                throw new RuntimeException('The restore did not finish within the timeout of the backup schedule.');
            }
            try {
                $state = self::send([...$request, 'url' => BackupNodeVolume::downloadUrl($s3, (string) $execution->filename)]);
                $connectionErrors = 0;
            } catch (ConnectionException $exception) {
                if (++$connectionErrors >= 5) {
                    throw $exception;
                }
                Sleep::for(self::POLL_SECONDS)->seconds();

                continue;
            }
            self::record($workload, ['state' => 'running', 'bytes_done' => $state['bytes_done'], 'bytes_total' => $state['bytes_total']]);
            if ($state['state'] === 'succeeded') {
                return;
            }
            if ($state['state'] === 'failed') {
                throw new RuntimeException('The backup could not be restored: '.($state['error'] !== '' ? $state['error'] : 'Sentinel reported no reason.'));
            }
            Sleep::for(self::POLL_SECONDS)->seconds();
        }
    }

    /**
     * The schedule of an execution that the database can restore: a successful dump of this
     * database with a checksum and a copy in S3.
     */
    public static function check(NodeWorkload $workload, ScheduledDatabaseBackupExecution $execution): ScheduledDatabaseBackup
    {
        $backup = $execution->scheduledDatabaseBackup;
        if ($backup === null || ! $backup->isNodeDatabase() || (int) $backup->database_id !== $workload->id) {
            throw new RuntimeException('The backup does not belong to this database.');
        }
        DumpNodeDatabase::workload($backup);
        $live = $execution->s3Replicas()->where('s3_uploaded', true)->where('s3_storage_deleted', false)->exists();
        if ($execution->status !== 'success' || ! $live || ! preg_match('/^[0-9a-f]{64}$/', (string) $execution->sha256) || blank($execution->filename)) {
            throw new RuntimeException('Only a successful backup with a copy in S3 can be restored.');
        }

        return $backup;
    }

    /**
     * The S3 storage that holds the dump, checked again at run time: it must belong to the team of
     * the database. The schedule can name another storage by now.
     */
    private static function storage(ScheduledDatabaseBackupExecution $execution, NodeWorkload $workload): S3Storage
    {
        $s3 = S3Storage::query()
            ->where('team_id', $workload->team_id)
            ->whereIn('id', $execution->s3Replicas()->where('s3_uploaded', true)->where('s3_storage_deleted', false)->select('s3_storage_id'))
            ->first();
        if ($s3 === null) {
            throw new RuntimeException('The S3 storage of this backup does not exist or belongs to another team.');
        }

        return $s3;
    }

    /** @return array<string, mixed> */
    public static function state(NodeWorkload $workload): array
    {
        return Cache::get(self::cacheKey($workload), []);
    }

    /** @param  array<string, mixed>  $values */
    public static function record(NodeWorkload $workload, array $values): void
    {
        Cache::put(self::cacheKey($workload), [...self::state($workload), ...$values, 'updated_at' => now()->toIso8601String()], now()->addHours(self::STATE_HOURS));
    }

    private static function cacheKey(NodeWorkload $workload): string
    {
        return 'node-database-restore:'.$workload->id;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array{state: string, bytes_total: int, bytes_done: int, error: string}
     */
    private static function send(array $request): array
    {
        $commandId = (string) Str::uuid();
        try {
            $data = SendFluxCommand::run('database.restore', [...$request, 'command_id' => $commandId]);
        } catch (RuntimeException $exception) {
            throw new RuntimeException(BackupNodeVolume::redactUrls($exception->getMessage()), $exception->getCode());
        }
        $validator = Validator::make($data, [
            'command_id' => ['required', 'string', 'in:'.$commandId],
            'state' => ['required', 'in:running,succeeded,failed'],
            'bytes_total' => ['required', 'integer', 'min:0'],
            'bytes_done' => ['required', 'integer', 'min:0'],
            'error' => ['nullable', 'string', 'max:4000'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Flux returned an invalid database restore state.');
        }
        $validated = $validator->validated();

        return [
            'state' => $validated['state'],
            'bytes_total' => (int) $validated['bytes_total'],
            'bytes_done' => (int) $validated['bytes_done'],
            'error' => BackupNodeVolume::redactUrls((string) ($validated['error'] ?? '')),
        ];
    }
}
