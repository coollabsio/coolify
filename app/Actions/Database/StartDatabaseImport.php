<?php

namespace App\Actions\Database;

use App\Enums\ProcessStatus;
use App\Models\S3Storage;
use App\Models\ServiceDatabase;
use App\Models\SwarmDocker;
use App\Rules\SafeWebhookUrl;
use App\Support\DatabaseBackupFileValidator;
use App\Support\DatabaseImport\DatabaseImportCommandBuilder;
use App\Support\DatabaseImport\DatabaseImportException;
use App\Support\DatabaseImport\DatabaseImportSource;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Activitylog\Models\Activity;

class StartDatabaseImport
{
    use AsAction;

    public const MAX_BYTES = 10 * 1024 * 1024 * 1024;

    public function __construct(private readonly DatabaseImportCommandBuilder $commands) {}

    public function handle(Model $resource, DatabaseImportSource $source, int $teamId): Activity
    {
        if (! $this->commands->supports($resource)) {
            throw new DatabaseImportException('Database imports are not supported for this database type.');
        }
        if (! str($resource->status)->startsWith('running')) {
            throw new DatabaseImportException('The database must be running before an import can start.');
        }

        [$server, $container, $network] = $this->target($resource);
        $destination = $resource instanceof ServiceDatabase ? $resource->service?->destination : $resource->destination;
        if ($destination instanceof SwarmDocker) {
            throw new DatabaseImportException('Database imports are not supported for Swarm servers yet.', 501);
        }
        if (! $server || ! ValidationPatterns::isValidContainerName($container)) {
            throw new DatabaseImportException('The database server or container is invalid.', 400);
        }

        $active = Activity::query()->where('properties->team_id', $teamId)
            ->where('properties->type_uuid', $resource->uuid)
            ->where('properties->operation', 'database_import')
            ->whereIn('properties->status', [ProcessStatus::QUEUED->value, ProcessStatus::IN_PROGRESS->value])
            ->exists();
        if ($active) {
            throw new DatabaseImportException('A database import is already running.', 409);
        }

        $operation = (string) Str::uuid();
        $containerPath = "/tmp/restore_{$operation}";
        $scriptPath = "/tmp/restore_{$operation}.sh";
        $commandList = [];
        $cleanup = ['container' => $container, 'containerTmpPath' => $containerPath, 'scriptPath' => $scriptPath, 'serverId' => $server->id];

        if ($source->type === 'upload') {
            $staged = $source->uploadId
                ? "upload/imports/{$teamId}/{$resource->uuid}/{$source->uploadId}/restore"
                : "upload/{$resource->uuid}/restore";
            if (! Storage::exists($staged)) {
                throw new DatabaseImportException('The completed upload was not found.');
            }
            $local = Storage::path($staged);
            if ($this->commands->databaseType($resource) === 'postgresql' && DatabaseBackupFileValidator::fileContainsPostgresqlProgramExecution($local)) {
                Storage::delete($staged);
                throw new DatabaseImportException('The uploaded backup contains disallowed PostgreSQL restore directives.');
            }
            $serverPath = "/tmp/database-import-{$operation}";
            instant_scp($local, $serverPath, $server);
            $source->uploadId ? Storage::deleteDirectory(dirname($staged)) : Storage::delete($staged);
            $commandList[] = 'docker cp '.escapeshellarg($serverPath).' '.escapeshellarg("{$container}:{$containerPath}");
            $commandList[] = 'rm -f '.escapeshellarg($serverPath);
            $cleanup['serverTmpPath'] = $serverPath;
        } elseif ($source->type === 'server') {
            $this->assertServerPath($source->path);
            $size = (int) trim((string) instant_remote_process(['stat -c %s -- '.escapeshellarg($source->path)], $server));
            if ($size < 1 || $size > self::MAX_BYTES) {
                throw new DatabaseImportException('The backup file is empty or exceeds the 10 GiB limit.');
            }
            $commandList[] = 'docker cp '.escapeshellarg($source->path).' '.escapeshellarg("{$container}:{$containerPath}");
        } else {
            $storage = S3Storage::ownedByCurrentTeamAPI($teamId)
                ->where(fn ($query) => $query->whereUuid($source->s3StorageUuid)->orWhere('id', ctype_digit((string) $source->s3StorageUuid) ? (int) $source->s3StorageUuid : -1))
                ->where('is_usable', true)->first();
            if (! $storage || ! ValidationPatterns::isValidS3BucketName($storage->bucket)) {
                throw new DatabaseImportException('S3 storage was not found or has an invalid bucket.');
            }
            $key = ltrim((string) $source->path, '/');
            $this->assertS3Path($key);
            $disk = Storage::build(['driver' => 's3', 'region' => $storage->region, 'key' => $storage->key, 'secret' => $storage->secret, 'bucket' => $storage->bucket, 'endpoint' => $storage->endpoint, 'use_path_style_endpoint' => true, 'http' => SafeWebhookUrl::httpClientOptions($storage->endpoint)]);
            if (! $disk->exists($key) || $disk->size($key) > self::MAX_BYTES) {
                throw new DatabaseImportException('The S3 backup was not found or exceeds the 10 GiB limit.');
            }
            $helper = "s3-restore-{$operation}";
            $serverPath = "/tmp/s3-restore-{$operation}";
            $sourceArg = escapeshellarg("s3temp/{$storage->bucket}/{$key}");
            $commandList = [
                'docker rm -f '.escapeshellarg($helper).' 2>/dev/null || true',
                'docker run -d --network '.escapeshellarg($network).' --name '.escapeshellarg($helper).' '.escapeshellarg(coolifyHelperImage().':'.getHelperVersion()).' sleep 3600',
                'docker exec '.escapeshellarg($helper).' mc alias set s3temp '.escapeshellarg($storage->endpoint).' '.escapeshellarg($storage->key).' '.escapeshellarg($storage->secret),
                'docker exec '.escapeshellarg($helper).' mc cp '.$sourceArg.' /tmp/restore',
                'docker cp '.escapeshellarg("{$helper}:/tmp/restore").' '.escapeshellarg($serverPath),
                'docker cp '.escapeshellarg($serverPath).' '.escapeshellarg("{$container}:{$containerPath}"),
                'docker rm -f '.escapeshellarg($helper).' 2>/dev/null || true',
                'rm -f '.escapeshellarg($serverPath),
            ];
            $cleanup += ['containerName' => $helper, 'serverTmpPath' => $serverPath];
        }

        if ($safety = $this->commands->buildPostgresSafetyCommand($resource, $container, $containerPath)) {
            $commandList[] = $safety;
        }
        $restore = base64_encode($this->commands->buildRestoreCommand($resource, $containerPath, $source->dumpAll));
        $commandList[] = 'echo '.escapeshellarg($restore).' | base64 -d > '.escapeshellarg($scriptPath);
        $commandList[] = 'chmod +x '.escapeshellarg($scriptPath);
        $commandList[] = 'docker cp '.escapeshellarg($scriptPath).' '.escapeshellarg("{$container}:{$scriptPath}");
        $commandList[] = 'rm -f '.escapeshellarg($scriptPath);
        $commandList[] = 'docker exec '.escapeshellarg($container).' sh -c '.escapeshellarg($scriptPath);

        $activity = remote_process($commandList, $server, type_uuid: $resource->uuid, model: $resource, callEventOnFinish: 'DatabaseImportFinished', callEventData: $cleanup);
        $activity->properties = $activity->properties->merge(['operation' => 'database_import', 'resource_kind' => $resource instanceof ServiceDatabase ? 'service_database' : 'standalone_database', 'operation_uuid' => $operation]);
        $activity->save();

        return $activity;
    }

    private function target(Model $resource): array
    {
        if ($resource instanceof ServiceDatabase) {
            return [$resource->service?->server, $resource->name.'-'.$resource->service?->uuid, $resource->service?->destination?->network ?? 'coolify'];
        }

        return [$resource->destination?->server, $resource->uuid, $resource->destination?->network ?? 'coolify'];
    }

    private function assertServerPath(?string $path): void
    {
        if (! $path || ! str_starts_with($path, '/') || preg_match('/\.\.|[$()`|;&><\r\n\0\'"\\\\]/', $path) || ! DatabaseBackupFileValidator::hasAllowedExtension(basename($path))) {
            throw new DatabaseImportException('The server path is invalid.');
        }
    }

    private function assertS3Path(string $path): void
    {
        if ($path === '' || preg_match('/\.\.|[$()`|;&><\r\n\0\'"\\\\]/', $path) || ! DatabaseBackupFileValidator::hasAllowedExtension(basename($path))) {
            throw new DatabaseImportException('The S3 path is invalid.');
        }
    }
}
