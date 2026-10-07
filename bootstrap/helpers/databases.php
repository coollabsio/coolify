<?php

use App\Models\DatabaseBackupS3Replica;
use App\Models\EnvironmentVariable;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\StandaloneSqlite;
use App\Models\SwarmDocker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\StreamedResponse;

function create_standalone_postgresql($environmentId, StandaloneDocker|SwarmDocker $destination, ?array $otherData = null, string $databaseImage = 'postgres:16-alpine'): StandalonePostgresql
{
    $database = new StandalonePostgresql;
    $database->uuid = new_public_id();
    $database->name = 'postgresql-database-'.$database->uuid;
    $database->image = $databaseImage;
    $database->postgres_password = Str::password(length: 64, symbols: false);
    $database->environment_id = $environmentId;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function create_standalone_redis($environment_id, StandaloneDocker|SwarmDocker $destination, ?array $otherData = null): StandaloneRedis
{
    $database = new StandaloneRedis;
    $database->uuid = new_public_id();
    $database->name = 'redis-database-'.$database->uuid;

    $redis_password = Str::password(length: 64, symbols: false);
    if ($otherData && isset($otherData['redis_password'])) {
        $redis_password = $otherData['redis_password'];
        unset($otherData['redis_password']);
    }

    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    EnvironmentVariable::create([
        'key' => 'REDIS_PASSWORD',
        'value' => $redis_password,
        'resourceable_type' => StandaloneRedis::class,
        'resourceable_id' => $database->id,
        'is_shared' => false,
    ]);

    EnvironmentVariable::create([
        'key' => 'REDIS_USERNAME',
        'value' => 'default',
        'resourceable_type' => StandaloneRedis::class,
        'resourceable_id' => $database->id,
        'is_shared' => false,
    ]);

    return $database;
}

function create_standalone_mongodb($environment_id, StandaloneDocker|SwarmDocker $destination, ?array $otherData = null): StandaloneMongodb
{
    $database = new StandaloneMongodb;
    $database->uuid = new_public_id();
    $database->name = 'mongodb-database-'.$database->uuid;
    $database->mongo_initdb_root_password = Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function create_standalone_mysql($environment_id, StandaloneDocker|SwarmDocker $destination, ?array $otherData = null): StandaloneMysql
{
    $database = new StandaloneMysql;
    $database->uuid = new_public_id();
    $database->name = 'mysql-database-'.$database->uuid;
    $database->mysql_root_password = Str::password(length: 64, symbols: false);
    $database->mysql_password = Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function create_standalone_mariadb($environment_id, StandaloneDocker|SwarmDocker $destination, ?array $otherData = null): StandaloneMariadb
{
    $database = new StandaloneMariadb;
    $database->uuid = new_public_id();
    $database->name = 'mariadb-database-'.$database->uuid;
    $database->mariadb_root_password = Str::password(length: 64, symbols: false);
    $database->mariadb_password = Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function create_standalone_keydb($environment_id, StandaloneDocker|SwarmDocker $destination, ?array $otherData = null): StandaloneKeydb
{
    $database = new StandaloneKeydb;
    $database->uuid = new_public_id();
    $database->name = 'keydb-database-'.$database->uuid;
    $database->keydb_password = Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function create_standalone_dragonfly($environment_id, StandaloneDocker|SwarmDocker $destination, ?array $otherData = null): StandaloneDragonfly
{
    $database = new StandaloneDragonfly;
    $database->uuid = new_public_id();
    $database->name = 'dragonfly-database-'.$database->uuid;
    $database->dragonfly_password = Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function create_standalone_clickhouse($environment_id, StandaloneDocker|SwarmDocker $destination, ?array $otherData = null): StandaloneClickhouse
{
    $database = new StandaloneClickhouse;
    $database->uuid = new_public_id();
    $database->name = 'clickhouse-database-'.$database->uuid;
    $database->clickhouse_admin_password = Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function create_standalone_sqlite($environment_id, StandaloneDocker|SwarmDocker $destination, ?array $otherData = null): StandaloneSqlite
{
    $database = new StandaloneSqlite;
    $database->uuid = new_public_id();
    $database->name = 'sqlite-database-'.$database->uuid;
    $database->image = 'peakimages/sqlite:3.53.4-v0.1.0';
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

/**
 * Shell line that pipes $source into $sink and writes the output to $escapedFile. It fails when
 * either command fails: a plain pipe only reports the exit status of $sink, so a failed dump
 * would still leave a small, valid archive. POSIX sh (dash, BusyBox ash); no pipefail needed.
 */
function pipeToFileKeepingExitStatus(string $source, string $sink, string $escapedFile): string
{
    return 'status=$( { { '.$source.'; echo $? >&3; } | '.$sink.' > '.$escapedFile.'; } 3>&1 ) && [ "$status" -eq 0 ]';
}

function deleteBackupsLocally(string|array|null $filenames, Server $server, bool $throwError = false): void
{
    if (empty($filenames)) {
        return;
    }
    if (is_string($filenames)) {
        $filenames = [$filenames];
    }
    $quotedFiles = array_map(fn ($file) => escapeshellarg($file), $filenames);
    instant_remote_process(['rm -f '.implode(' ', $quotedFiles)], $server, throwError: $throwError);

    $foldersToCheck = collect($filenames)->map(fn ($file) => dirname($file))->unique();
    $foldersToCheck->each(fn ($folder) => deleteEmptyBackupFolder($folder, $server));
}

function streamBackupFromServer(Server $server, string $filename, string $contentType): StreamedResponse
{
    $privateKey = $server->privateKey;
    if (! $privateKey || $privateKey->team_id !== $server->team_id) {
        throw new RuntimeException('Private key not found for this server.');
    }

    $disk = Storage::build([
        'driver' => 'sftp',
        'host' => $server->ip,
        'port' => (int) $server->port,
        'username' => $server->user,
        'privateKey' => $privateKey->getKeyLocation(),
        'root' => '/',
    ]);

    if (! $disk->exists($filename)) {
        throw new FileNotFoundException($filename);
    }

    return new StreamedResponse(function () use ($disk, $filename) {
        if (ob_get_level()) {
            ob_end_clean();
        }
        $stream = $disk->readStream($filename);
        if ($stream === false || is_null($stream)) {
            abort(500, 'Failed to open stream for the requested file.');
        }
        while (! feof($stream)) {
            echo fread($stream, 2048);
            flush();
        }

        fclose($stream);
    }, 200, [
        'Content-Type' => $contentType,
        'Content-Disposition' => 'attachment; filename="'.basename($filename).'"',
    ]);
}

function deleteBackupsS3(string|array|null $filenames, S3Storage $s3): void
{
    if (empty($filenames) || ! $s3) {
        return;
    }
    if (is_string($filenames)) {
        $filenames = [$filenames];
    }

    $disk = Storage::build([
        'driver' => 's3',
        'key' => $s3->key,
        'secret' => $s3->secret,
        'region' => $s3->region,
        'bucket' => $s3->bucket,
        'endpoint' => $s3->endpoint,
        'use_path_style_endpoint' => true,
        'aws_url' => $s3->awsUrl(),
        'throw' => true,
    ]);

    try {
        $deleted = $disk->delete($filenames);
    } catch (Throwable $exception) {
        throw new RuntimeException('One or more S3 backup files could not be deleted.', previous: $exception);
    }

    if (! $deleted) {
        throw new RuntimeException('One or more S3 backup files could not be deleted.');
    }
}

function deleteEmptyBackupFolder($folderPath, Server $server): void
{
    $escapedPath = escapeshellarg($folderPath);
    $escapedParentPath = escapeshellarg(dirname($folderPath));

    $checkEmpty = instant_remote_process(["[ -d $escapedPath ] && [ -z \"$(ls -A $escapedPath)\" ] && echo 'empty' || echo 'not empty'"], $server, throwError: false);

    if (trim($checkEmpty) === 'empty') {
        instant_remote_process(["rmdir $escapedPath"], $server, throwError: false);
        $checkParentEmpty = instant_remote_process(["[ -d $escapedParentPath ] && [ -z \"$(ls -A $escapedParentPath)\" ] && echo 'empty' || echo 'not empty'"], $server, throwError: false);
        if (trim($checkParentEmpty) === 'empty') {
            instant_remote_process(["rmdir $escapedParentPath"], $server, throwError: false);
        }
    }
}

function removeOldBackups($backup): void
{
    // When local backups are disabled, retain the local file until an S3 copy is available.
    $localBackupsToDelete = deleteOldBackupsLocally($backup);
    if ($localBackupsToDelete->isNotEmpty()) {
        $backup->executions()
            ->whereIn('id', $localBackupsToDelete->pluck('id'))
            ->update(['local_storage_deleted' => true]);
    }

    if ($backup->save_s3) {
        deleteOldBackupsFromS3($backup);
    }

    // Delete execution records when no local file and no S3 copy is left
    $backup->executions()
        ->where('local_storage_deleted', true)
        ->withoutLiveS3Copies()
        ->delete();
}

/**
 * Successful executions that fall outside the retention settings, newest execution first.
 *
 * @param  Collection<int, ScheduledDatabaseBackupExecution>  $executions  Sorted newest first.
 * @return Collection<int, ScheduledDatabaseBackupExecution>
 */
function backupExecutionsOutsideRetention(Collection $executions, ?int $retentionAmount, ?int $retentionDays, int|float|null $maxStorageGB): Collection
{
    if ($executions->isEmpty()) {
        return collect();
    }

    $backupsToDelete = collect();

    if ($retentionAmount > 0) {
        $backupsToDelete = $backupsToDelete->merge($executions->skip($retentionAmount));
    }

    if ($retentionDays > 0) {
        $oldestAllowedDate = $executions->first()->created_at->clone()->utc()->subDays($retentionDays);
        $backupsToDelete = $backupsToDelete->merge(
            $executions->filter(fn ($execution) => $execution->created_at->utc() < $oldestAllowedDate)
        );
    }

    if ($maxStorageGB > 0) {
        $maxStorageBytes = $maxStorageGB * pow(1024, 3);
        $totalSize = 0;

        foreach ($executions->skip(1) as $backupExecution) {
            $totalSize += (int) $backupExecution->size;
            if ($totalSize > $maxStorageBytes) {
                $backupsToDelete = $backupsToDelete->merge($executions->filter(
                    fn ($b) => $b->created_at->utc() <= $backupExecution->created_at->utc()
                )->skip(1));
                break;
            }
        }
    }

    return $backupsToDelete->unique('id')->values();
}

function deleteOldBackupsLocally($backup): Collection
{
    if (! $backup) {
        return collect();
    }

    $successfulBackups = $backup->executions()
        ->where('status', 'success')
        ->where('local_storage_deleted', false)
        ->when($backup->disable_local_backup, fn ($query) => $query
            ->whereHas('s3Replicas', fn ($query) => $query
                ->where('s3_uploaded', true)
                ->where('s3_storage_deleted', false)))
        ->orderBy('created_at', 'desc')
        ->orderBy('id', 'desc')
        ->get();

    $backupsToDelete = backupExecutionsOutsideRetention(
        $successfulBackups,
        $backup->database_backup_retention_amount_locally,
        $backup->database_backup_retention_days_locally,
        $backup->database_backup_retention_max_storage_locally,
    );

    if ($backupsToDelete->isEmpty()) {
        return collect();
    }

    if ($backup->database_type === ServiceDatabase::class) {
        $server = $backup->database->service->server;
    } else {
        $server = $backup->database->destination->server;
    }

    if (! $server) {
        return collect();
    }

    $filesToDelete = $backupsToDelete
        ->filter(fn ($execution) => ! empty($execution->filename))
        ->pluck('filename')
        ->all();

    if (empty($filesToDelete)) {
        return collect();
    }

    deleteBackupsLocally($filesToDelete, $server);

    return $backupsToDelete;
}

/**
 * Applies the S3 retention settings to each destination separately. Copies in a storage that no longer exists are
 * marked as deleted.
 *
 * @return Collection<int, ScheduledDatabaseBackupExecution> The executions that lost at least one S3 copy.
 */
function deleteOldBackupsFromS3($backup): Collection
{
    if (! $backup) {
        return collect();
    }

    $liveReplicas = DatabaseBackupS3Replica::query()
        ->with('s3')
        ->whereHas('execution', fn ($query) => $query
            ->where('scheduled_database_backup_id', $backup->id)
            ->where('status', 'success'))
        ->where('s3_uploaded', true)
        ->where('s3_storage_deleted', false)
        ->get();

    $deletedReplicas = $liveReplicas->filter(fn (DatabaseBackupS3Replica $replica) => ! $replica->s3);

    foreach ($liveReplicas->filter(fn (DatabaseBackupS3Replica $replica) => $replica->s3)->groupBy('s3_storage_id') as $replicas) {
        $successfulBackups = $backup->executions()
            ->whereIn('id', $replicas->pluck('execution_id'))
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $backupsToDelete = backupExecutionsOutsideRetention(
            $successfulBackups,
            $backup->database_backup_retention_amount_s3,
            $backup->database_backup_retention_days_s3,
            $backup->database_backup_retention_max_storage_s3,
        );

        $filesToDelete = $backupsToDelete
            ->filter(fn ($execution) => ! empty($execution->filename))
            ->pluck('filename')
            ->all();

        if (empty($filesToDelete)) {
            continue;
        }

        try {
            deleteBackupsS3($filesToDelete, $replicas->first()->s3);
            $deletedReplicas = $deletedReplicas->merge($replicas->whereIn('execution_id', $backupsToDelete->pluck('id')));
        } catch (Throwable $e) {
            report($e);
        }
    }

    if ($deletedReplicas->isEmpty()) {
        return collect();
    }

    DatabaseBackupS3Replica::query()
        ->whereKey($deletedReplicas->pluck('id'))
        ->update(['s3_storage_deleted' => true]);

    $executions = $backup->executions()->whereIn('id', $deletedReplicas->pluck('execution_id')->unique())->get();
    $executions->each(fn (ScheduledDatabaseBackupExecution $execution) => $execution->refreshS3Summary());

    return $executions;
}

function isPublicPortAlreadyUsed(Server $server, int $port, ?string $id = null): bool
{
    if ($id) {
        $foundDatabase = $server->databases()->where('public_port', $port)->where('is_public', true)->where('id', '!=', $id)->first();
    } else {
        $foundDatabase = $server->databases()->where('public_port', $port)->where('is_public', true)->first();
    }
    if ($foundDatabase) {
        return true;
    }

    return false;
}
