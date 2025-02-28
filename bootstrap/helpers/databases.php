<?php

use App\Models\EnvironmentVariable;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Visus\Cuid2\Cuid2;

function generate_database_name(string $type): string
{
    return $type.'-database-'.(new Cuid2);
}

function create_standalone_postgresql($environmentId, $destinationUuid, ?array $otherData = null, string $databaseImage = 'postgres:16-alpine'): StandalonePostgresql
{
    $destination = StandaloneDocker::where('uuid', $destinationUuid)->firstOrFail();
    $database = new StandalonePostgresql;
    $database->name = generate_database_name('postgresql');
    $database->image = $databaseImage;
    $database->postgres_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->environment_id = $environmentId;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function create_standalone_redis($environment_id, $destination_uuid, ?array $otherData = null): StandaloneRedis
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->firstOrFail();
    $database = new StandaloneRedis;
    $database->name = generate_database_name('redis');
    $redis_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
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

function create_standalone_mongodb($environment_id, $destination_uuid, ?array $otherData = null): StandaloneMongodb
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->firstOrFail();
    $database = new StandaloneMongodb;
    $database->name = generate_database_name('mongodb');
    $database->mongo_initdb_root_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function create_standalone_mysql($environment_id, $destination_uuid, ?array $otherData = null): StandaloneMysql
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->firstOrFail();
    $database = new StandaloneMysql;
    $database->name = generate_database_name('mysql');
    $database->mysql_root_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->mysql_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function create_standalone_mariadb($environment_id, $destination_uuid, ?array $otherData = null): StandaloneMariadb
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->firstOrFail();
    $database = new StandaloneMariadb;
    $database->name = generate_database_name('mariadb');
    $database->mariadb_root_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->mariadb_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function create_standalone_keydb($environment_id, $destination_uuid, ?array $otherData = null): StandaloneKeydb
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->firstOrFail();
    $database = new StandaloneKeydb;
    $database->name = generate_database_name('keydb');
    $database->keydb_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function create_standalone_dragonfly($environment_id, $destination_uuid, ?array $otherData = null): StandaloneDragonfly
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->firstOrFail();
    $database = new StandaloneDragonfly;
    $database->name = generate_database_name('dragonfly');
    $database->dragonfly_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function create_standalone_clickhouse($environment_id, $destination_uuid, ?array $otherData = null): StandaloneClickhouse
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->firstOrFail();
    $database = new StandaloneClickhouse;
    $database->name = generate_database_name('clickhouse');
    $database->clickhouse_admin_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function deleteBackupsLocally(string|array|null $filenames, Server $server): void
{
    if (empty($filenames)) {
        return;
    }
    if (is_string($filenames)) {
        $filenames = [$filenames];
    }
    $quotedFiles = array_map(fn ($file) => "\"$file\"", $filenames);
    instant_remote_process(['rm -f '.implode(' ', $quotedFiles)], $server, throwError: false);

    $foldersToCheck = collect($filenames)->map(fn ($file) => dirname($file))->unique();
    $foldersToCheck->each(fn ($folder) => deleteEmptyBackupFolder($folder, $server));
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
    ]);

    $disk->delete($filenames);
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
    try {
        $processedBackups = deleteOldBackupsLocally($backup);

        if ($backup->save_s3) {
            $processedBackups = $processedBackups->merge(deleteOldBackupsFromS3($backup));
        }

        if ($processedBackups->isNotEmpty()) {
            $backup->executions()->whereIn('id', $processedBackups->pluck('id'))->delete();
        }
    } catch (\Exception $e) {
        throw $e;
    }
}

function deleteOldBackupsLocally($backup): Collection
{
    if (! $backup || ! $backup->executions) {
        return collect();
    }

    $successfulBackups = $backup->executions()
        ->where('status', 'success')
        ->orderBy('created_at', 'desc')
        ->get();

    if ($successfulBackups->isEmpty()) {
        return collect();
    }

    $retentionAmount = $backup->database_backup_retention_amount_locally;
    $retentionDays = $backup->database_backup_retention_days_locally;
    $maxStorageGB = $backup->database_backup_retention_max_storage_locally;

    if ($retentionAmount === 0 && $retentionDays === 0 && $maxStorageGB === 0) {
        return collect();
    }

    $backupsToDelete = collect();

    if ($retentionAmount > 0) {
        $byAmount = $successfulBackups->skip($retentionAmount);
        $backupsToDelete = $backupsToDelete->merge($byAmount);
    }

    if ($retentionDays > 0) {
        $oldestAllowedDate = $successfulBackups->first()->created_at->clone()->utc()->subDays($retentionDays);
        $oldBackups = $successfulBackups->filter(fn ($execution) => $execution->created_at->utc() < $oldestAllowedDate);
        $backupsToDelete = $backupsToDelete->merge($oldBackups);
    }

    if ($maxStorageGB > 0) {
        $maxStorageBytes = $maxStorageGB * pow(1024, 3);
        $totalSize = 0;
        $backupsOverLimit = collect();

        $backupsToCheck = $successfulBackups->skip(1);

        foreach ($backupsToCheck as $backupExecution) {
            $totalSize += (int) $backupExecution->size;
            if ($totalSize > $maxStorageBytes) {
                $backupsOverLimit = $successfulBackups->filter(
                    fn ($b) => $b->created_at->utc() <= $backupExecution->created_at->utc()
                )->skip(1);
                break;
            }
        }

        $backupsToDelete = $backupsToDelete->merge($backupsOverLimit);
    }

    $backupsToDelete = $backupsToDelete->unique('id');
    $processedBackups = collect();

    $server = null;
    if ($backup->database_type === \App\Models\ServiceDatabase::class) {
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

    if (! empty($filesToDelete)) {
        deleteBackupsLocally($filesToDelete, $server);
        $processedBackups = $backupsToDelete;
    }

    return $processedBackups;
}

function deleteOldBackupsFromS3($backup): Collection
{
    if (! $backup || ! $backup->executions || ! $backup->s3) {
        return collect();
    }

    $successfulBackups = $backup->executions()
        ->where('status', 'success')
        ->orderBy('created_at', 'desc')
        ->get();

    if ($successfulBackups->isEmpty()) {
        return collect();
    }

    $retentionAmount = $backup->database_backup_retention_amount_s3;
    $retentionDays = $backup->database_backup_retention_days_s3;
    $maxStorageGB = $backup->database_backup_retention_max_storage_s3;

    if ($retentionAmount === 0 && $retentionDays === 0 && $maxStorageGB === 0) {
        return collect();
    }

    $backupsToDelete = collect();

    if ($retentionAmount > 0) {
        $byAmount = $successfulBackups->skip($retentionAmount);
        $backupsToDelete = $backupsToDelete->merge($byAmount);
    }

    if ($retentionDays > 0) {
        $oldestAllowedDate = $successfulBackups->first()->created_at->clone()->utc()->subDays($retentionDays);
        $oldBackups = $successfulBackups->filter(fn ($execution) => $execution->created_at->utc() < $oldestAllowedDate);
        $backupsToDelete = $backupsToDelete->merge($oldBackups);
    }

    if ($maxStorageGB > 0) {
        $maxStorageBytes = $maxStorageGB * pow(1024, 3);
        $totalSize = 0;
        $backupsOverLimit = collect();

        $backupsToCheck = $successfulBackups->skip(1);

        foreach ($backupsToCheck as $backupExecution) {
            $totalSize += (int) $backupExecution->size;
            if ($totalSize > $maxStorageBytes) {
                $backupsOverLimit = $successfulBackups->filter(
                    fn ($b) => $b->created_at->utc() <= $backupExecution->created_at->utc()
                )->skip(1);
                break;
            }
        }

        $backupsToDelete = $backupsToDelete->merge($backupsOverLimit);
    }

    $backupsToDelete = $backupsToDelete->unique('id');
    $processedBackups = collect();

    $filesToDelete = $backupsToDelete
        ->filter(fn ($execution) => ! empty($execution->filename))
        ->pluck('filename')
        ->all();

    if (! empty($filesToDelete)) {
        deleteBackupsS3($filesToDelete, $backup->s3);
        $processedBackups = $backupsToDelete;
    }

    return $processedBackups;
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
