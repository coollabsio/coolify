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

function create_standalone_postgresql($environmentId, $destinationUuid, ?array $otherData = null, string $databaseImage = 'postgres:16-alpine'): StandalonePostgresql
{
    $destination = StandaloneDocker::where('uuid', $destinationUuid)->firstOrFail();
    $database = new StandalonePostgresql;
    $database->uuid = (new Cuid2);
    $database->name = 'postgresql-database-'.$database->uuid;
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
    $database->uuid = (new Cuid2);
    $database->name = 'redis-database-'.$database->uuid;

    $redis_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
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

function create_standalone_mongodb($environment_id, $destination_uuid, ?array $otherData = null): StandaloneMongodb
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->firstOrFail();
    $database = new StandaloneMongodb;
    $database->uuid = (new Cuid2);
    $database->name = 'mongodb-database-'.$database->uuid;
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
    $database->uuid = (new Cuid2);
    $database->name = 'mysql-database-'.$database->uuid;
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
    $database->uuid = (new Cuid2);
    $database->name = 'mariadb-database-'.$database->uuid;
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
    $database->uuid = (new Cuid2);
    $database->name = 'keydb-database-'.$database->uuid;
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
    $database->uuid = (new Cuid2);
    $database->name = 'dragonfly-database-'.$database->uuid;
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
    $database->uuid = (new Cuid2);
    $database->name = 'clickhouse-database-'.$database->uuid;
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
        if ($backup->executions) {
            // Delete old local backups (only if local backup is NOT disabled)
            // Note: When disable_local_backup is enabled, each execution already marks its own
            // local_storage_deleted status at the time of backup, so we don't need to retroactively
            // update old executions
            if (! $backup->disable_local_backup) {
                $localBackupsToDelete = deleteOldBackupsLocally($backup);
                if ($localBackupsToDelete->isNotEmpty()) {
                    $backup->executions()
                        ->whereIn('id', $localBackupsToDelete->pluck('id'))
                        ->update(['local_storage_deleted' => true]);
                }
            }
        }

        if ($backup->save_s3 && $backup->executions) {
            $s3BackupsToDelete = deleteOldBackupsFromS3($backup);
            if ($s3BackupsToDelete->isNotEmpty()) {
                $backup->executions()
                    ->whereIn('id', $s3BackupsToDelete->pluck('id'))
                    ->update(['s3_storage_deleted' => true]);
            }
        }

        // Delete execution records where all backup copies are gone
        // Case 1: Both local and S3 backups are deleted
        $backup->executions()
            ->where('local_storage_deleted', true)
            ->where('s3_storage_deleted', true)
            ->delete();

        // Case 2: Local backup is deleted and S3 was never used (s3_uploaded is null)
        $backup->executions()
            ->where('local_storage_deleted', true)
            ->whereNull('s3_uploaded')
            ->delete();

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
        ->where('local_storage_deleted', false)
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
        ->where('s3_storage_deleted', false)
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

function isPostgresContainerRunning(StandalonePostgresql $database): bool
{
    $server = $database->destination->server ?? null;
    if (! $server) {
        return false;
    }

    $containerName = $database->uuid;
    $nameFilter = '^/'.$containerName.'$';

    $result = instant_remote_process(
        ['docker ps -q -f name='.escapeshellarg($nameFilter)],
        $server,
        throwError: false,
    );

    return ! blank(trim($result));
}

function getPgbackrestInfo(StandalonePostgresql $database): ?array
{
    if (! $database->isPgbackrestEnabled()) {
        return null;
    }

    $server = $database->destination->server ?? null;
    if (! $server) {
        return null;
    }

    $containerName = $database->uuid;
    $stanzaName = $database->getPgbackrestStanzaName();

    try {
        $output = instant_remote_process(
            ["docker exec {$containerName} pgbackrest --stanza={$stanzaName} info --output=json"],
            $server,
            throwError: false,
        );

        if (blank($output)) {
            return null;
        }

        $info = json_decode($output, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($info)) {
            return null;
        }

        return $info;
    } catch (\Throwable) {
        return null;
    }
}

function getPgbackrestBackupList(StandalonePostgresql $database): Collection
{
    $info = getPgbackrestInfo($database);

    if (empty($info) || ! isset($info[0]['backup']) || ! is_array($info[0]['backup'])) {
        return collect();
    }

    return collect($info[0]['backup'])->map(function ($backup) {
        $dbList = [];
        if (isset($backup['database']) && is_array($backup['database'])) {
            $dbList = array_values(array_filter(array_column($backup['database'], 'name')));
        }

        $size = (int) ($backup['info']['size'] ?? 0);
        $repoSize = (int) ($backup['info']['repository']['size'] ?? 0);

        return [
            'label' => $backup['label'] ?? null,
            'type' => $backup['type'] ?? null,
            'size' => $size,
            'size_formatted' => formatBytes($size),
            'repository_size' => $repoSize,
            'repository_size_formatted' => formatBytes($repoSize),
            'timestamp_start' => $backup['timestamp']['start'] ?? null,
            'timestamp_stop' => $backup['timestamp']['stop'] ?? null,
            'started_at' => isset($backup['timestamp']['start']) ? \Carbon\Carbon::createFromTimestamp($backup['timestamp']['start']) : null,
            'finished_at' => isset($backup['timestamp']['stop']) ? \Carbon\Carbon::createFromTimestamp($backup['timestamp']['stop']) : null,
            'database_list' => $dbList,
            'prior' => $backup['prior'] ?? null,
        ];
    })->reverse()->values();
}

function getPgbackrestLatestBackup(StandalonePostgresql $database): ?array
{
    $backups = getPgbackrestBackupList($database);

    return $backups->first();
}

function getPgbackrestBackupByLabel(StandalonePostgresql $database, string $label): ?array
{
    $backups = getPgbackrestBackupList($database);

    return $backups->firstWhere('label', $label);
}

function getPgbackrestStanzaStatus(StandalonePostgresql $database): array
{
    if (! $database->isPgbackrestEnabled()) {
        return ['status' => 'disabled', 'message' => 'pgBackRest is not enabled'];
    }

    if (! isPostgresContainerRunning($database)) {
        return ['status' => 'container_stopped', 'message' => 'PostgreSQL container is not running'];
    }

    $info = getPgbackrestInfo($database);

    if ($info === null) {
        return ['status' => 'error', 'message' => 'Failed to get pgBackRest info'];
    }

    if (empty($info) || ! isset($info[0]) || ! is_array($info[0])) {
        return ['status' => 'no_stanza', 'message' => 'No stanza configured'];
    }

    $stanzaInfo = $info[0];
    $status = $stanzaInfo['status'] ?? [];

    if (isset($status['code']) && $status['code'] !== 0) {
        return [
            'status' => 'error',
            'message' => $status['message'] ?? 'Unknown error',
            'code' => $status['code'],
        ];
    }

    $backupCount = count($stanzaInfo['backup'] ?? []);

    return [
        'status' => 'ok',
        'message' => "Stanza is healthy with {$backupCount} backup(s)",
        'backup_count' => $backupCount,
        'cipher' => $stanzaInfo['cipher'] ?? 'none',
    ];
}

function calculatePgbackrestTotalSize(StandalonePostgresql $database): int
{
    return getPgbackrestBackupList($database)->sum('repository_size');
}

function formatPgbackrestBackupType(string $type): string
{
    return match ($type) {
        'full' => 'Full',
        'diff' => 'Differential',
        'incr' => 'Incremental',
        default => ucfirst($type),
    };
}

function isPgbackrestBackupDeletable(StandalonePostgresql $database, string $label): array
{
    $backups = getPgbackrestBackupList($database);

    $backup = $backups->firstWhere('label', $label);
    if (! $backup) {
        return ['deletable' => false, 'reason' => 'Backup not found in repository'];
    }

    $dependents = $backups->filter(function ($b) use ($label) {
        return ($b['prior'] ?? null) === $label;
    });

    if ($dependents->isNotEmpty()) {
        $dependentLabels = $dependents->pluck('label')->join(', ');

        return [
            'deletable' => false,
            'reason' => "This backup has dependent backups that would become unrestorable: {$dependentLabels}",
            'dependents' => $dependents->pluck('label')->toArray(),
        ];
    }

    return ['deletable' => true, 'reason' => null];
}

function deletePgbackrestBackup(StandalonePostgresql $database, string $label): array
{
    $deletableCheck = isPgbackrestBackupDeletable($database, $label);
    if (! $deletableCheck['deletable']) {
        return ['success' => false, 'message' => $deletableCheck['reason']];
    }

    if (! isPostgresContainerRunning($database)) {
        return ['success' => false, 'message' => 'PostgreSQL container is not running'];
    }

    $containerName = $database->uuid;
    $stanzaName = $database->getPgbackrestStanzaName();
    $server = $database->destination->server;

    $expireCommand = "set +e; docker exec {$containerName} pgbackrest --stanza=".escapeshellarg($stanzaName).' --set='.escapeshellarg($label).' expire 2>&1; EXIT_CODE=$?; set -e; echo "EXIT_CODE:${EXIT_CODE}"';

    $output = instant_remote_process([$expireCommand], $server, throwError: false);

    $exitCode = 0;
    if (preg_match('/EXIT_CODE:(\d+)$/', $output, $matches)) {
        $exitCode = (int) $matches[1];
        $output = preg_replace('/EXIT_CODE:\d+$/', '', $output);
    }

    if ($exitCode !== 0) {
        return ['success' => false, 'message' => "Failed to expire backup: {$output}"];
    }

    return ['success' => true, 'message' => 'Backup deleted from pgBackRest repository'];
}
