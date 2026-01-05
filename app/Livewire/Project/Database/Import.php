<?php

namespace App\Livewire\Project\Database;

use App\Models\S3Storage;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class Import extends Component
{
    use AuthorizesRequests;

    /**
     * Validate that a string is safe for use as an S3 bucket name.
     * Allows alphanumerics, dots, dashes, and underscores.
     */
    private function validateBucketName(string $bucket): bool
    {
        return preg_match('/^[a-zA-Z0-9.\-_]+$/', $bucket) === 1;
    }

    /**
     * Validate that a string is safe for use as an S3 path.
     * Allows alphanumerics, dots, dashes, underscores, slashes, and common file characters.
     */
    private function validateS3Path(string $path): bool
    {
        // Must not be empty
        if (empty($path)) {
            return false;
        }

        // Must not contain dangerous shell metacharacters or command injection patterns
        $dangerousPatterns = [
            '..', // Directory traversal
            '$(', // Command substitution
            '`',  // Backtick command substitution
            '|',  // Pipe
            ';',  // Command separator
            '&',  // Background/AND
            '>',  // Redirect
            '<',  // Redirect
            "\n", // Newline
            "\r", // Carriage return
            "\0", // Null byte
            "'",  // Single quote
            '"',  // Double quote
            '\\', // Backslash
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (str_contains($path, $pattern)) {
                return false;
            }
        }

        // Allow alphanumerics, dots, dashes, underscores, slashes, spaces, plus, equals, at
        return preg_match('/^[a-zA-Z0-9.\-_\/\s+@=]+$/', $path) === 1;
    }

    /**
     * Validate that a string is safe for use as a file path on the server.
     */
    private function validateServerPath(string $path): bool
    {
        // Must be an absolute path
        if (! str_starts_with($path, '/')) {
            return false;
        }

        // Must not contain dangerous shell metacharacters or command injection patterns
        $dangerousPatterns = [
            '..', // Directory traversal
            '$(', // Command substitution
            '`',  // Backtick command substitution
            '|',  // Pipe
            ';',  // Command separator
            '&',  // Background/AND
            '>',  // Redirect
            '<',  // Redirect
            "\n", // Newline
            "\r", // Carriage return
            "\0", // Null byte
            "'",  // Single quote
            '"',  // Double quote
            '\\', // Backslash
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (str_contains($path, $pattern)) {
                return false;
            }
        }

        // Allow alphanumerics, dots, dashes, underscores, slashes, and spaces
        return preg_match('/^[a-zA-Z0-9.\-_\/\s]+$/', $path) === 1;
    }

    public bool $unsupported = false;

    public $resource;

    public $parameters;

    public $containers;

    public bool $scpInProgress = false;

    public bool $importRunning = false;

    public ?string $filename = null;

    public ?string $filesize = null;

    public bool $isUploading = false;

    public int $progress = 0;

    public bool $error = false;

    public Server $server;

    public string $container;

    public array $importCommands = [];

    public bool $dumpAll = false;

    public string $restoreCommandText = '';

    public string $customLocation = '';

    public ?int $activityId = null;

    public string $postgresqlRestoreCommand = 'pg_restore -U $POSTGRES_USER -d $POSTGRES_DB';

    public string $mysqlRestoreCommand = 'mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE';

    public string $mariadbRestoreCommand = 'mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE';

    public string $mongodbRestoreCommand = 'mongorestore --authenticationDatabase=admin --username $MONGO_INITDB_ROOT_USERNAME --password $MONGO_INITDB_ROOT_PASSWORD --uri mongodb://localhost:27017 --gzip --archive=';

    // S3 Restore properties
    public $availableS3Storages = [];

    public ?int $s3StorageId = null;

    public string $s3Path = '';

    public ?int $s3FileSize = null;

    public function getListeners()
    {
        $userId = Auth::id();

        return [
            "echo-private:user.{$userId},DatabaseStatusChanged" => '$refresh',
            'slideOverClosed' => 'resetActivityId',
        ];
    }

    public function resetActivityId()
    {
        $this->activityId = null;
    }

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->getContainers();
        $this->loadAvailableS3Storages();
    }

    public function updatedDumpAll($value)
    {
        switch ($this->resource->getMorphClass()) {
            case \App\Models\StandaloneMariadb::class:
                if ($value === true) {
                    $this->mariadbRestoreCommand = <<<'EOD'
for pid in $(mariadb -u root -p$MARIADB_ROOT_PASSWORD -N -e "SELECT id FROM information_schema.processlist WHERE user != 'root';"); do
  mariadb -u root -p$MARIADB_ROOT_PASSWORD -e "KILL $pid" 2>/dev/null || true
done && \
mariadb -u root -p$MARIADB_ROOT_PASSWORD -N -e "SELECT CONCAT('DROP DATABASE IF EXISTS \`',schema_name,'\`;') FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','mysql','performance_schema','sys');" | mariadb -u root -p$MARIADB_ROOT_PASSWORD && \
mariadb -u root -p$MARIADB_ROOT_PASSWORD -e "CREATE DATABASE IF NOT EXISTS \`default\`;" && \
(gunzip -cf $tmpPath 2>/dev/null || cat $tmpPath) | sed -e '/^CREATE DATABASE/d' -e '/^USE \`mysql\`/d' | mariadb -u root -p$MARIADB_ROOT_PASSWORD default
EOD;
                    $this->restoreCommandText = $this->mariadbRestoreCommand.' && (gunzip -cf <temp_backup_file> 2>/dev/null || cat <temp_backup_file>) | mariadb -u root -p$MARIADB_ROOT_PASSWORD default';
                } else {
                    $this->mariadbRestoreCommand = 'mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE';
                }
                break;
            case \App\Models\StandaloneMysql::class:
                if ($value === true) {
                    $this->mysqlRestoreCommand = <<<'EOD'
for pid in $(mysql -u root -p$MYSQL_ROOT_PASSWORD -N -e "SELECT id FROM information_schema.processlist WHERE user != 'root';"); do
  mysql -u root -p$MYSQL_ROOT_PASSWORD -e "KILL $pid" 2>/dev/null || true
done && \
mysql -u root -p$MYSQL_ROOT_PASSWORD -N -e "SELECT CONCAT('DROP DATABASE IF EXISTS \`',schema_name,'\`;') FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','mysql','performance_schema','sys');" | mysql -u root -p$MYSQL_ROOT_PASSWORD && \
mysql -u root -p$MYSQL_ROOT_PASSWORD -e "CREATE DATABASE IF NOT EXISTS \`default\`;" && \
(gunzip -cf $tmpPath 2>/dev/null || cat $tmpPath) | sed -e '/^CREATE DATABASE/d' -e '/^USE \`mysql\`/d' | mysql -u root -p$MYSQL_ROOT_PASSWORD default
EOD;
                    $this->restoreCommandText = $this->mysqlRestoreCommand.' && (gunzip -cf <temp_backup_file> 2>/dev/null || cat <temp_backup_file>) | mysql -u root -p$MYSQL_ROOT_PASSWORD default';
                } else {
                    $this->mysqlRestoreCommand = 'mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE';
                }
                break;
            case \App\Models\StandalonePostgresql::class:
                if ($value === true) {
                    $this->postgresqlRestoreCommand = <<<'EOD'
psql -U $POSTGRES_USER -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname IS NOT NULL AND pid <> pg_backend_pid()" && \
psql -U $POSTGRES_USER -t -c "SELECT datname FROM pg_database WHERE NOT datistemplate" | xargs -I {} dropdb -U $POSTGRES_USER --if-exists {} && \
createdb -U $POSTGRES_USER postgres
EOD;
                    $this->restoreCommandText = $this->postgresqlRestoreCommand.' && (gunzip -cf <temp_backup_file> 2>/dev/null || cat <temp_backup_file>) | psql -U $POSTGRES_USER postgres';
                } else {
                    $this->postgresqlRestoreCommand = 'pg_restore -U $POSTGRES_USER -d $POSTGRES_DB';
                }
                break;
        }

    }

    public function getContainers()
    {
        $this->containers = collect();
        if (! data_get($this->parameters, 'database_uuid')) {
            abort(404);
        }
        $resource = getResourceByUuid($this->parameters['database_uuid'], data_get(auth()->user()->currentTeam(), 'id'));
        if (is_null($resource)) {
            abort(404);
        }
        $this->authorize('view', $resource);
        $this->resource = $resource;
        $this->server = $this->resource->destination->server;
        $this->container = $this->resource->uuid;
        if (str(data_get($this, 'resource.status'))->startsWith('running')) {
            $this->containers->push($this->container);
        }

        if (
            $this->resource->getMorphClass() === \App\Models\StandaloneRedis::class ||
            $this->resource->getMorphClass() === \App\Models\StandaloneKeydb::class ||
            $this->resource->getMorphClass() === \App\Models\StandaloneDragonfly::class ||
            $this->resource->getMorphClass() === \App\Models\StandaloneClickhouse::class
        ) {
            $this->unsupported = true;
        }
    }

    public function checkFile()
    {
        if (filled($this->customLocation)) {
            // Validate the custom location to prevent command injection
            if (! $this->validateServerPath($this->customLocation)) {
                $this->dispatch('error', 'Invalid file path. Path must be absolute and contain only safe characters (alphanumerics, dots, dashes, underscores, slashes).');

                return;
            }

            try {
                $escapedPath = escapeshellarg($this->customLocation);
                $result = instant_remote_process(["ls -l {$escapedPath}"], $this->server, throwError: false);
                if (blank($result)) {
                    $this->dispatch('error', 'The file does not exist or has been deleted.');

                    return;
                }
                $this->filename = $this->customLocation;
                $this->dispatch('success', 'The file exists.');
            } catch (\Throwable $e) {
                return handleError($e, $this);
            }
        }
    }

    public function runImport()
    {
        $this->authorize('update', $this->resource);

        if ($this->filename === '') {
            $this->dispatch('error', 'Please select a file to import.');

            return;
        }
        try {
            $this->importRunning = true;
            $this->importCommands = [];
            $backupFileName = "upload/{$this->resource->uuid}/restore";

            // Check if an uploaded file exists first (takes priority over custom location)
            if (Storage::exists($backupFileName)) {
                $path = Storage::path($backupFileName);
                $tmpPath = '/tmp/'.basename($backupFileName).'_'.$this->resource->uuid;
                instant_scp($path, $tmpPath, $this->server);
                Storage::delete($backupFileName);
                $this->importCommands[] = "docker cp {$tmpPath} {$this->container}:{$tmpPath}";
            } elseif (filled($this->customLocation)) {
                // Validate the custom location to prevent command injection
                if (! $this->validateServerPath($this->customLocation)) {
                    $this->dispatch('error', 'Invalid file path. Path must be absolute and contain only safe characters.');

                    return;
                }
                $tmpPath = '/tmp/restore_'.$this->resource->uuid;
                $escapedCustomLocation = escapeshellarg($this->customLocation);
                $this->importCommands[] = "docker cp {$escapedCustomLocation} {$this->container}:{$tmpPath}";
            } else {
                $this->dispatch('error', 'The file does not exist or has been deleted.');

                return;
            }

            // Copy the restore command to a script file
            $scriptPath = "/tmp/restore_{$this->resource->uuid}.sh";

            $restoreCommand = $this->buildRestoreCommand($tmpPath);

            $restoreCommandBase64 = base64_encode($restoreCommand);
            $this->importCommands[] = "echo \"{$restoreCommandBase64}\" | base64 -d > {$scriptPath}";
            $this->importCommands[] = "chmod +x {$scriptPath}";
            $this->importCommands[] = "docker cp {$scriptPath} {$this->container}:{$scriptPath}";

            $this->importCommands[] = "docker exec {$this->container} sh -c '{$scriptPath}'";
            $this->importCommands[] = "docker exec {$this->container} sh -c 'echo \"Import finished with exit code $?\"'";

            if (! empty($this->importCommands)) {
                $activity = remote_process($this->importCommands, $this->server, ignore_errors: true, callEventOnFinish: 'RestoreJobFinished', callEventData: [
                    'scriptPath' => $scriptPath,
                    'tmpPath' => $tmpPath,
                    'container' => $this->container,
                    'serverId' => $this->server->id,
                ]);

                // Track the activity ID
                $this->activityId = $activity->id;

                // Dispatch activity to the monitor and open slide-over
                $this->dispatch('activityMonitor', $activity->id);
                $this->dispatch('databaserestore');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->filename = null;
            $this->importCommands = [];
        }
    }

    public function loadAvailableS3Storages()
    {
        try {
            $this->availableS3Storages = S3Storage::ownedByCurrentTeam(['id', 'name', 'description'])
                ->where('is_usable', true)
                ->get();
        } catch (\Throwable $e) {
            $this->availableS3Storages = collect();
        }
    }

    public function updatedS3Path($value)
    {
        // Reset validation state when path changes
        $this->s3FileSize = null;

        // Ensure path starts with a slash
        if ($value !== null && $value !== '') {
            $this->s3Path = str($value)->trim()->start('/')->value();
        }
    }

    public function updatedS3StorageId()
    {
        // Reset validation state when storage changes
        $this->s3FileSize = null;
    }

    public function checkS3File()
    {
        if (! $this->s3StorageId) {
            $this->dispatch('error', 'Please select an S3 storage.');

            return;
        }

        if (blank($this->s3Path)) {
            $this->dispatch('error', 'Please provide an S3 path.');

            return;
        }

        // Clean the path (remove leading slash if present)
        $cleanPath = ltrim($this->s3Path, '/');

        // Validate the S3 path early to prevent command injection in subsequent operations
        if (! $this->validateS3Path($cleanPath)) {
            $this->dispatch('error', 'Invalid S3 path. Path must contain only safe characters (alphanumerics, dots, dashes, underscores, slashes).');

            return;
        }

        try {
            $s3Storage = S3Storage::ownedByCurrentTeam()->findOrFail($this->s3StorageId);

            // Validate bucket name early
            if (! $this->validateBucketName($s3Storage->bucket)) {
                $this->dispatch('error', 'Invalid S3 bucket name. Bucket name must contain only alphanumerics, dots, dashes, and underscores.');

                return;
            }

            // Test connection
            $s3Storage->testConnection();

            // Build S3 disk configuration
            $disk = Storage::build([
                'driver' => 's3',
                'region' => $s3Storage->region,
                'key' => $s3Storage->key,
                'secret' => $s3Storage->secret,
                'bucket' => $s3Storage->bucket,
                'endpoint' => $s3Storage->endpoint,
                'use_path_style_endpoint' => true,
            ]);

            // Check if file exists
            if (! $disk->exists($cleanPath)) {
                $this->dispatch('error', 'File not found in S3. Please check the path.');

                return;
            }

            // Get file size
            $this->s3FileSize = $disk->size($cleanPath);

            $this->dispatch('success', 'File found in S3. Size: '.formatBytes($this->s3FileSize));
        } catch (\Throwable $e) {
            $this->s3FileSize = null;

            return handleError($e, $this);
        }
    }

    public function restoreFromS3()
    {
        $this->authorize('update', $this->resource);

        if (! $this->s3StorageId || blank($this->s3Path)) {
            $this->dispatch('error', 'Please select S3 storage and provide a path first.');

            return;
        }

        if (is_null($this->s3FileSize)) {
            $this->dispatch('error', 'Please check the file first by clicking "Check File".');

            return;
        }

        try {
            $this->importRunning = true;

            $s3Storage = S3Storage::ownedByCurrentTeam()->findOrFail($this->s3StorageId);

            $key = $s3Storage->key;
            $secret = $s3Storage->secret;
            $bucket = $s3Storage->bucket;
            $endpoint = $s3Storage->endpoint;

            // Validate bucket name to prevent command injection
            if (! $this->validateBucketName($bucket)) {
                $this->dispatch('error', 'Invalid S3 bucket name. Bucket name must contain only alphanumerics, dots, dashes, and underscores.');

                return;
            }

            // Clean the S3 path
            $cleanPath = ltrim($this->s3Path, '/');

            // Validate the S3 path to prevent command injection
            if (! $this->validateS3Path($cleanPath)) {
                $this->dispatch('error', 'Invalid S3 path. Path must contain only safe characters (alphanumerics, dots, dashes, underscores, slashes).');

                return;
            }

            // Get helper image
            $helperImage = config('constants.coolify.helper_image');
            $latestVersion = getHelperVersion();
            $fullImageName = "{$helperImage}:{$latestVersion}";

            // Get the database destination network
            $destinationNetwork = $this->resource->destination->network ?? 'coolify';

            // Generate unique names for this operation
            $containerName = "s3-restore-{$this->resource->uuid}";
            $helperTmpPath = '/tmp/'.basename($cleanPath);
            $serverTmpPath = "/tmp/s3-restore-{$this->resource->uuid}-".basename($cleanPath);
            $containerTmpPath = "/tmp/restore_{$this->resource->uuid}-".basename($cleanPath);
            $scriptPath = "/tmp/restore_{$this->resource->uuid}.sh";

            // Prepare all commands in sequence
            $commands = [];

            // 1. Clean up any existing helper container and temp files from previous runs
            $commands[] = "docker rm -f {$containerName} 2>/dev/null || true";
            $commands[] = "rm -f {$serverTmpPath} 2>/dev/null || true";
            $commands[] = "docker exec {$this->container} rm -f {$containerTmpPath} {$scriptPath} 2>/dev/null || true";

            // 2. Start helper container on the database network
            $commands[] = "docker run -d --network {$destinationNetwork} --name {$containerName} {$fullImageName} sleep 3600";

            // 3. Configure S3 access in helper container
            $escapedEndpoint = escapeshellarg($endpoint);
            $escapedKey = escapeshellarg($key);
            $escapedSecret = escapeshellarg($secret);
            $commands[] = "docker exec {$containerName} mc alias set s3temp {$escapedEndpoint} {$escapedKey} {$escapedSecret}";

            // 4. Check file exists in S3 (bucket and path already validated above)
            $escapedBucket = escapeshellarg($bucket);
            $escapedCleanPath = escapeshellarg($cleanPath);
            $escapedS3Source = escapeshellarg("s3temp/{$bucket}/{$cleanPath}");
            $commands[] = "docker exec {$containerName} mc stat {$escapedS3Source}";

            // 5. Download from S3 to helper container (progress shown by default)
            $escapedHelperTmpPath = escapeshellarg($helperTmpPath);
            $commands[] = "docker exec {$containerName} mc cp {$escapedS3Source} {$escapedHelperTmpPath}";

            // 6. Copy from helper to server, then immediately to database container
            $commands[] = "docker cp {$containerName}:{$helperTmpPath} {$serverTmpPath}";
            $commands[] = "docker cp {$serverTmpPath} {$this->container}:{$containerTmpPath}";

            // 7. Cleanup helper container and server temp file immediately (no longer needed)
            $commands[] = "docker rm -f {$containerName} 2>/dev/null || true";
            $commands[] = "rm -f {$serverTmpPath} 2>/dev/null || true";

            // 8. Build and execute restore command inside database container
            $restoreCommand = $this->buildRestoreCommand($containerTmpPath);

            $restoreCommandBase64 = base64_encode($restoreCommand);
            $commands[] = "echo \"{$restoreCommandBase64}\" | base64 -d > {$scriptPath}";
            $commands[] = "chmod +x {$scriptPath}";
            $commands[] = "docker cp {$scriptPath} {$this->container}:{$scriptPath}";

            // 9. Execute restore and cleanup temp files immediately after completion
            $commands[] = "docker exec {$this->container} sh -c '{$scriptPath} && rm -f {$containerTmpPath} {$scriptPath}'";
            $commands[] = "docker exec {$this->container} sh -c 'echo \"Import finished with exit code $?\"'";

            // Execute all commands with cleanup event (as safety net for edge cases)
            $activity = remote_process($commands, $this->server, ignore_errors: true, callEventOnFinish: 'S3RestoreJobFinished', callEventData: [
                'containerName' => $containerName,
                'serverTmpPath' => $serverTmpPath,
                'scriptPath' => $scriptPath,
                'containerTmpPath' => $containerTmpPath,
                'container' => $this->container,
                'serverId' => $this->server->id,
            ]);

            // Track the activity ID
            $this->activityId = $activity->id;

            // Dispatch activity to the monitor and open slide-over
            $this->dispatch('activityMonitor', $activity->id);
            $this->dispatch('databaserestore');
            $this->dispatch('info', 'Restoring database from S3. Progress will be shown in the activity monitor...');
        } catch (\Throwable $e) {
            $this->importRunning = false;

            return handleError($e, $this);
        }
    }

    public function buildRestoreCommand(string $tmpPath): string
    {
        switch ($this->resource->getMorphClass()) {
            case \App\Models\StandaloneMariadb::class:
                $restoreCommand = $this->mariadbRestoreCommand;
                if ($this->dumpAll) {
                    $restoreCommand .= " && (gunzip -cf {$tmpPath} 2>/dev/null || cat {$tmpPath}) | mariadb -u root -p\$MARIADB_ROOT_PASSWORD";
                } else {
                    $restoreCommand .= " < {$tmpPath}";
                }
                break;
            case \App\Models\StandaloneMysql::class:
                $restoreCommand = $this->mysqlRestoreCommand;
                if ($this->dumpAll) {
                    $restoreCommand .= " && (gunzip -cf {$tmpPath} 2>/dev/null || cat {$tmpPath}) | mysql -u root -p\$MYSQL_ROOT_PASSWORD";
                } else {
                    $restoreCommand .= " < {$tmpPath}";
                }
                break;
            case \App\Models\StandalonePostgresql::class:
                $restoreCommand = $this->postgresqlRestoreCommand;
                if ($this->dumpAll) {
                    $restoreCommand .= " && (gunzip -cf {$tmpPath} 2>/dev/null || cat {$tmpPath}) | psql -U \$POSTGRES_USER postgres";
                } else {
                    $restoreCommand .= " {$tmpPath}";
                }
                break;
            case \App\Models\StandaloneMongodb::class:
                $restoreCommand = $this->mongodbRestoreCommand;
                if ($this->dumpAll === false) {
                    $restoreCommand .= "{$tmpPath}";
                }
                break;
            default:
                $restoreCommand = '';
        }

        return $restoreCommand;
    }
}
