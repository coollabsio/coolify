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
        'standalone_redis_id' => $database->id,
        'is_shared' => false,
    ]);

    EnvironmentVariable::create([
        'key' => 'REDIS_USERNAME',
        'value' => 'default',
        'standalone_redis_id' => $database->id,
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
        'bucket_endpoint' => $s3->isHetzner() || $s3->isDigitalOcean(),
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

function deleteOldBackupsLocally($backup): void
{
    if (! $backup || ! $backup->executions) {
        return;
    }

    $successfulBackups = $backup->executions()
        ->where('status', 'success')
        ->orderBy('created_at', 'desc')
        ->get();

    if ($successfulBackups->isEmpty()) {
        return;
    }

    $retentionAmount = $backup->database_backup_retention_amount_locally;
    $retentionDays = $backup->database_backup_retention_days_locally;

    if ($retentionAmount === 0 && $retentionDays === 0) {
        return;
    }

    $backupsToDelete = collect();

    if ($retentionAmount > 0) {
        $backupsToDelete = $backupsToDelete->merge($successfulBackups->skip($retentionAmount));
    }

    if ($retentionDays > 0) {
        $oldestAllowedDate = $successfulBackups->first()->created_at->clone()->utc()->subDays($retentionDays);
        $oldBackups = $successfulBackups->filter(fn ($execution) => $execution->created_at->utc() < $oldestAllowedDate);
        $backupsToDelete = $backupsToDelete->merge($oldBackups);
    }

    $backupsToDelete = $backupsToDelete->unique('id');
    $foldersToCheck = collect();

    $backupsToDelete->chunk(10)->each(function ($chunk) use ($backup, &$foldersToCheck) {
        $executionIds = [];
        $filesToDelete = [];

        foreach ($chunk as $execution) {
            if ($execution->filename) {
                $filesToDelete[] = $execution->filename;
                $executionIds[] = $execution->id;
                $foldersToCheck->push(dirname($execution->filename));
            }
        }

        if (! empty($filesToDelete)) {
            deleteBackupsLocally($filesToDelete, $backup->server);
            if (! empty($executionIds)) {
                $backup->executions()->whereIn('id', $executionIds)->delete();
            }
        }
    });

    $foldersToCheck->unique()->each(fn ($folder) => deleteEmptyBackupFolder($folder, $backup->server));
}

function deleteOldBackupsFromS3($backup): void
{
    if (! $backup || ! $backup->executions || ! $backup->s3) {
        return;
    }

    $successfulBackups = $backup->executions()
        ->where('status', 'success')
        ->orderBy('created_at', 'desc')
        ->get();

    if ($successfulBackups->isEmpty()) {
        return;
    }

    $retentionAmount = $backup->database_backup_retention_amount_s3;
    $retentionDays = $backup->database_backup_retention_days_s3;
    $maxStorageGB = $backup->database_backup_retention_max_storage_s3;

    if ($retentionAmount === 0 && $retentionDays === 0 && $maxStorageGB === 0) {
        return;
    }

    $backupsToDelete = collect();

    if ($retentionAmount > 0) {
        $backupsToDelete = $backupsToDelete->merge($successfulBackups->skip($retentionAmount));
    }

    if ($retentionDays > 0) {
        $oldestAllowedDate = $successfulBackups->first()->created_at->clone()->utc()->subDays($retentionDays);
        $oldBackups = $successfulBackups->filter(fn ($execution) => $execution->created_at->utc() < $oldestAllowedDate);
        $backupsToDelete = $backupsToDelete->merge($oldBackups);
    }

    if ($maxStorageGB > 0) {
        $maxStorageBytes = $maxStorageGB * 1024 * 1024 * 1024;
        $totalSize = 0;
        $backupsOverLimit = collect();

        foreach ($successfulBackups as $backup) {
            $totalSize += (int) $backup->size;
            if ($totalSize > $maxStorageBytes) {
                $backupsOverLimit = $successfulBackups->filter(fn ($b) => $b->created_at->utc() <= $backup->created_at->utc());
                break;
            }
        }

        $backupsToDelete = $backupsToDelete->merge($backupsOverLimit);
    }

    $backupsToDelete = $backupsToDelete->unique('id');
    $foldersToCheck = collect();

    $backupsToDelete->chunk(10)->each(function ($chunk) use ($backup, &$foldersToCheck) {
        $executionIds = [];
        $filesToDelete = [];

        foreach ($chunk as $execution) {
            if ($execution->filename) {
                $filesToDelete[] = $execution->filename;
                $executionIds[] = $execution->id;
                $foldersToCheck->push(dirname($execution->filename));
            }
        }

        if (! empty($filesToDelete)) {
            deleteBackupsS3($filesToDelete, $backup->server, $backup->s3);
            if (! empty($executionIds)) {
                $backup->executions()
                    ->whereIn('id', $executionIds)
                    ->update(['s3_backup_deleted_at' => now()]);
            }
        }
    });

    $foldersToCheck->unique()->each(fn ($folder) => deleteEmptyBackupFolder($folder, $backup->server));
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
