<?php

namespace App\Actions\Database;

use App\Models\S3Storage;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Models\SwarmDocker;
use App\Support\DatabaseBackupFileValidator;
use App\Support\DatabaseImport\DatabaseImportCommandBuilder;
use App\Support\DatabaseImport\DatabaseImportException;
use App\Support\DatabaseImport\DatabaseImportSource;
use App\Support\DatabaseOperationReservation;
use App\Support\ResourceStartActivity;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Activitylog\Models\Activity;
use Throwable;

class StartDatabaseImport
{
    use AsAction;

    public const MAX_BYTES = 10 * 1024 * 1024 * 1024;

    public const LOCK_SECONDS = 1800;

    public function __construct(private readonly DatabaseImportCommandBuilder $commands) {}

    public static function lockKey(string $resourceUuid): string
    {
        return "database-import:{$resourceUuid}";
    }

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

        $lock = Cache::lock(self::lockKey($resource->uuid), self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new DatabaseImportException('A database import is already running.', 409);
        }

        // A start or restart can be queued without an activity yet: its reservation blocks the import.
        // The import holds the reservation until its own activity exists.
        $reservation = null;

        try {
            $reservation = DatabaseOperationReservation::acquire($resource->uuid);
            if ($reservation === null) {
                throw new DatabaseImportException(ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE, 409);
            }

            return $this->startImport($resource, $source, $teamId, $server, $container, $network);
        } finally {
            DatabaseOperationReservation::release($resource->uuid, $reservation);
            $lock->release();
        }
    }

    private function startImport(Model $resource, DatabaseImportSource $source, int $teamId, Server $server, string $container, string $network): Activity
    {
        $active = ResourceStartActivity::active($resource->uuid, ResourceStartActivity::DATABASE_IMPORT_OPERATION, $teamId);
        if (ResourceStartActivity::failStale($active)->isNotEmpty()) {
            throw new DatabaseImportException('A database import is already running.', 409);
        }
        $activeStarts = ResourceStartActivity::active($resource->uuid, ResourceStartActivity::DATABASE_START_OPERATION, $teamId);
        if (ResourceStartActivity::failStale($activeStarts)->isNotEmpty()) {
            throw new DatabaseImportException(ResourceStartActivity::DATABASE_OPERATION_IN_PROGRESS_MESSAGE, 409);
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
            $magic = bin2hex((string) file_get_contents($local, length: 6));
            instant_scp($local, $serverPath, $server);
            $source->uploadId ? Storage::deleteDirectory(dirname($staged)) : Storage::delete($staged);
            $commandList = [...$commandList, ...$this->copyIntoContainer($serverPath, $magic, $server, $operation, $container, $containerPath, $cleanup)];
            $commandList[] = 'rm -f '.escapeshellarg($serverPath);
            $cleanup['serverTmpPath'] = $serverPath;
        } elseif ($source->type === 'server') {
            $this->assertServerPath($source->path);
            $size = (int) trim((string) instant_remote_process(['stat -c %s -- '.escapeshellarg($source->path)], $server));
            if ($size < 1 || $size > self::MAX_BYTES) {
                throw new DatabaseImportException('The backup file is empty or exceeds the 10 GiB limit.');
            }
            $magic = (string) instant_remote_process(['head -c 6 -- '.escapeshellarg($source->path).' | od -An -tx1'], $server);
            $commandList = [...$commandList, ...$this->copyIntoContainer($source->path, $magic, $server, $operation, $container, $containerPath, $cleanup)];
        } else {
            $storage = S3Storage::ownedByCurrentTeamAPI($teamId)
                ->where(fn ($query) => $query->whereUuid($source->s3StorageUuid)->orWhere('id', ctype_digit((string) $source->s3StorageUuid) ? (int) $source->s3StorageUuid : -1))
                ->where('is_usable', true)->first();
            if (! $storage || ! ValidationPatterns::isValidS3BucketName($storage->bucket)) {
                throw new DatabaseImportException('S3 storage was not found or has an invalid bucket.');
            }
            $key = ltrim((string) $source->path, '/');
            $this->assertS3Path($key);
            $disk = $storage->filesystem();
            if (! $disk->exists($key) || $disk->size($key) > self::MAX_BYTES) {
                throw new DatabaseImportException('The S3 backup was not found or exceeds the 10 GiB limit.');
            }
            $helper = "s3-restore-{$operation}";
            $this->startS3HelperWithEnv($storage, $server, $helper, $network);
            $sourceArg = escapeshellarg("s3temp/{$storage->bucket}/{$key}");
            $commandList = [
                'docker exec '.escapeshellarg($helper).' sh -c '.escapeshellarg('mc alias set s3temp "$S3_ENDPOINT" "$S3_ACCESS_KEY" "$S3_SECRET_KEY"'),
                'docker exec '.escapeshellarg($helper).' mc cp '.$sourceArg.' /tmp/restore',
                ...$this->streamFromHelper($helper, $container, $containerPath),
                'docker rm -f '.escapeshellarg($helper).' 2>/dev/null || true',
            ];
            $cleanup['containerName'] = $helper;
        }

        if ($safety = $this->commands->buildPostgresSafetyCommand($resource, $container, $containerPath)) {
            $commandList[] = $safety;
        }
        $restore = base64_encode($this->commands->buildRestoreCommand($resource, $containerPath, $source->dumpAll, $source->replaceExisting));
        $commandList[] = 'echo '.escapeshellarg($restore).' | base64 -d > '.escapeshellarg($scriptPath);
        $commandList[] = 'chmod +x '.escapeshellarg($scriptPath);
        $commandList[] = 'docker cp '.escapeshellarg($scriptPath).' '.escapeshellarg("{$container}:{$scriptPath}");
        $commandList[] = 'rm -f '.escapeshellarg($scriptPath);
        $commandList[] = 'docker exec '.escapeshellarg($container).' sh -c '.escapeshellarg($scriptPath);

        // The operation properties are set when the activity is created: the CoolifyTask job can
        // load and save the activity before a later update, which would drop them again.
        return remote_process($commandList, $server, type_uuid: $resource->uuid, model: $resource, callEventOnFinish: 'DatabaseImportFinished', callEventData: $cleanup, properties: [
            'operation' => ResourceStartActivity::DATABASE_IMPORT_OPERATION,
            'resource_kind' => $resource instanceof ServiceDatabase ? 'service_database' : 'standalone_database',
            'operation_uuid' => $operation,
        ]);
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

    /**
     * Copies a backup into the database container. bz2, xz and zip backups go through
     * the Coolify helper image, because database images do not ship those tools.
     *
     * @param  array<string, mixed>  $cleanup
     * @return list<string>
     */
    private function copyIntoContainer(string $path, string $magic, Server $server, string $operation, string $container, string $containerPath, array &$cleanup): array
    {
        $magic = strtolower((string) preg_replace('/[^0-9a-f]/i', '', $magic));
        if (! preg_match('/^(425a68|fd377a585a00|504b0304)/', $magic)) {
            return ['docker cp '.escapeshellarg($path).' '.escapeshellarg("{$container}:{$containerPath}")];
        }

        $helper = "backup-decompress-{$operation}";
        $image = escapeshellarg(coolifyHelperImage().':'.getHelperVersion());

        try {
            instant_remote_process([
                'docker rm -f '.escapeshellarg($helper).' 2>/dev/null || true',
                'docker run -d --network none --name '.escapeshellarg($helper).' '.$image.' sleep 86400',
            ], $server);
        } catch (Throwable) {
            instant_remote_process(['docker rm -f '.escapeshellarg($helper).' 2>/dev/null || true'], $server, throwError: false);

            throw new DatabaseImportException('Unable to start the backup decompression helper.');
        }
        $cleanup['containerName'] = $helper;

        return [
            'docker cp '.escapeshellarg($path).' '.escapeshellarg("{$helper}:/tmp/restore"),
            ...$this->streamFromHelper($helper, $container, $containerPath),
            'docker rm -f '.escapeshellarg($helper).' 2>/dev/null || true',
        ];
    }

    /**
     * Prepares /tmp/restore in a helper container, then streams it into the database
     * container. Preparing first keeps decompression failures from being lost in a pipe.
     *
     * @return list<string>
     */
    private function streamFromHelper(string $helper, string $container, string $containerPath): array
    {
        $target = escapeshellarg($containerPath);
        $writer = "cat > {$target} && [ -s {$target} ] || { echo 'The backup is empty or could not be read.' >&2; exit 1; }";

        return [
            'docker exec '.escapeshellarg($helper).' sh -c '.escapeshellarg($this->commands->buildNormalizeScript('/tmp/restore', '/tmp/restore.prepared')),
            'docker exec '.escapeshellarg($helper).' cat /tmp/restore.prepared | docker exec -i '.escapeshellarg($container).' sh -c '.escapeshellarg($writer),
        ];
    }

    private function startS3HelperWithEnv(S3Storage $storage, Server $server, string $helper, string $network): void
    {
        $image = escapeshellarg(coolifyHelperImage().':'.getHelperVersion());

        try {
            instant_remote_process([
                'docker rm -f '.escapeshellarg($helper).' 2>/dev/null || true',
                'docker run -d --network '.escapeshellarg($network)
                    .' --name '.escapeshellarg($helper)
                    .' -e S3_ENDPOINT='.escapeshellarg((string) $storage->endpoint)
                    .' -e S3_ACCESS_KEY='.escapeshellarg((string) $storage->key)
                    .' -e S3_SECRET_KEY='.escapeshellarg((string) $storage->secret)
                    .' '.$image.' sleep 86400',
            ], $server);
        } catch (Throwable) {
            instant_remote_process(['docker rm -f '.escapeshellarg($helper).' 2>/dev/null || true'], $server, throwError: false);

            throw new DatabaseImportException('Unable to start the S3 restore helper.');
        }
    }
}
