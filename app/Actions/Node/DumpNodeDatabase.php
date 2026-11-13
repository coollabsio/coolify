<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Models\NodeWorkload;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use Illuminate\Support\Carbon;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Dumps one database of a cluster database to S3 (decision 0010). Sentinel runs the fixed dump
 * command of the engine in the container and uploads it through presigned part URLs, like a
 * volume backup (decision 0009). The request names the engine and the database, never a command.
 */
class DumpNodeDatabase
{
    use AsAction;

    public const array CAPABILITIES = ['database.dump.v1', 'database.restore.v1'];

    /** The database name of an execution that dumped every database of the instance. */
    public const string ALL = 'all';

    /** @return array{size: int, sha256: string} */
    public function handle(ScheduledDatabaseBackup $backup, ScheduledDatabaseBackupExecution $execution, Carbon $deadline): array
    {
        $workload = self::workload($backup);
        $node = self::node($workload);
        $s3 = self::storage($backup, $workload);

        return BackupNodeVolume::upload('database.dump', self::request($workload, $node, $execution), $s3, (string) $execution->filename, $deadline,
            fn (int $bytes) => $execution->update(['size' => $bytes]));
    }

    public static function workload(ScheduledDatabaseBackup $backup): NodeWorkload
    {
        $workload = $backup->database;
        if (! $workload instanceof NodeWorkload || $workload->database_type?->dumpEngine() === null) {
            throw new RuntimeException('Dump backups are not available for this database.');
        }

        return $workload;
    }

    /** The Node of the database, which must report the dump capabilities. */
    public static function node(NodeWorkload $workload): Node
    {
        $node = $workload->nodes()->orderBy('nodes.id')->first();
        if ($node === null) {
            throw new RuntimeException('The database has no server. Deploy it first.');
        }
        foreach (self::CAPABILITIES as $capability) {
            $node->ensureReportedCapability($capability);
        }

        return $node;
    }

    /**
     * The S3 storage of the schedule, checked again at run time: it must belong to the team of the
     * database, whatever team the schedule records.
     */
    public static function storage(ScheduledDatabaseBackup $backup, NodeWorkload $workload): S3Storage
    {
        $s3 = S3Storage::query()
            ->whereKey($backup->s3_storage_id)
            ->where('team_id', $workload->team_id)
            ->first();
        if ($s3 === null) {
            throw new RuntimeException('The S3 storage of this backup does not exist or belongs to another team. Select an S3 storage of the team.');
        }

        return $s3;
    }

    /**
     * The database of the request: empty for a dump of the whole instance.
     */
    public static function requestDatabase(ScheduledDatabaseBackupExecution $execution): string
    {
        return $execution->database_name === self::ALL ? '' : (string) $execution->database_name;
    }

    /** The S3 key of a new dump. */
    public static function key(NodeWorkload $workload, string $database): string
    {
        $extension = match ($workload->database_type->dumpEngine()) {
            'postgresql' => $database === self::ALL ? 'sql' : 'dump',
            'mysql', 'mariadb' => 'sql',
            'mongodb' => 'archive',
            default => 'rdb',
        };

        return 'database-backups/'.$workload->team_id.'/'.$workload->uuid.'/'
            .str($database)->slug().'-'.now()->utc()->format('Ymd-His').'.'.$extension.'.zst';
    }

    /**
     * Stops the dump on the Node and aborts every unfinished upload of its key, after a worker
     * stopped during a backup. Best effort.
     */
    public static function abandon(ScheduledDatabaseBackup $backup, ScheduledDatabaseBackupExecution $execution): void
    {
        if (blank($execution->filename)) {
            return;
        }
        try {
            $workload = self::workload($backup);
            $node = $workload->nodes()->orderBy('nodes.id')->first();
            if ($node !== null) {
                BackupNodeVolume::send('database.dump', [...self::request($workload, $node, $execution), 'abort' => true]);
            }
        } catch (Throwable) {
        }
        try {
            $s3 = self::storage($backup, self::workload($backup));
            $client = BackupNodeVolume::client($s3);
            $uploads = $client->listMultipartUploads(['Bucket' => $s3->bucket, 'Prefix' => $execution->filename])['Uploads'] ?? [];
            foreach ($uploads as $upload) {
                if (($upload['Key'] ?? null) === $execution->filename) {
                    $client->abortMultipartUpload(['Bucket' => $s3->bucket, 'Key' => $upload['Key'], 'UploadId' => $upload['UploadId']]);
                }
            }
        } catch (Throwable) {
        }
    }

    /** @return array<string, mixed> */
    private static function request(NodeWorkload $workload, Node $node, ScheduledDatabaseBackupExecution $execution): array
    {
        return [
            'server_id' => $node->uuid,
            'dump_id' => $execution->uuid,
            'container_name' => $workload->containerNameOn($node),
            'engine' => $workload->database_type->dumpEngine(),
            'database' => self::requestDatabase($execution),
        ];
    }
}
