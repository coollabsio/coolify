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

    public string $postgresqlRestoreCommand = 'pg_restore -U $POSTGRES_USER -d $POSTGRES_DB';

    public string $mysqlRestoreCommand = 'mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE';

    public string $mariadbRestoreCommand = 'mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE';

    public string $mongodbRestoreCommand = 'mongorestore --authenticationDatabase=admin --username $MONGO_INITDB_ROOT_USERNAME --password $MONGO_INITDB_ROOT_PASSWORD --uri mongodb://localhost:27017 --gzip --archive=';

    // S3 Restore properties
    public $availableS3Storages = [];

    public ?int $s3StorageId = null;

    public string $s3Path = '';

    public ?string $s3DownloadedFile = null;

    public ?int $s3FileSize = null;

    public bool $s3DownloadInProgress = false;

    public function getListeners()
    {
        $userId = Auth::id();

        return [
            "echo-private:user.{$userId},DatabaseStatusChanged" => '$refresh',
        ];
    }

    public function mount()
    {
        if (isDev()) {
            $this->customLocation = '/data/coolify/pg-dump-all-1736245863.gz';
        }
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
            try {
                $result = instant_remote_process(["ls -l {$this->customLocation}"], $this->server, throwError: false);
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
            if (filled($this->customLocation)) {
                $backupFileName = '/tmp/restore_'.$this->resource->uuid;
                $this->importCommands[] = "docker cp {$this->customLocation} {$this->container}:{$backupFileName}";
                $tmpPath = $backupFileName;
            } else {
                $backupFileName = "upload/{$this->resource->uuid}/restore";
                $path = Storage::path($backupFileName);
                if (! Storage::exists($backupFileName)) {
                    $this->dispatch('error', 'The file does not exist or has been deleted.');

                    return;
                }
                $tmpPath = '/tmp/'.basename($backupFileName).'_'.$this->resource->uuid;
                instant_scp($path, $tmpPath, $this->server);
                Storage::delete($backupFileName);
                $this->importCommands[] = "docker cp {$tmpPath} {$this->container}:{$tmpPath}";
            }

            // Copy the restore command to a script file
            $scriptPath = "/tmp/restore_{$this->resource->uuid}.sh";

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
            }

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
                $this->dispatch('activityMonitor', $activity->id);
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
            ray($e);
        }
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

        try {
            $s3Storage = S3Storage::ownedByCurrentTeam()->findOrFail($this->s3StorageId);

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

            // Clean the path (remove leading slash if present)
            $cleanPath = ltrim($this->s3Path, '/');

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

    public function downloadFromS3()
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
            $this->s3DownloadInProgress = true;

            $s3Storage = S3Storage::ownedByCurrentTeam()->findOrFail($this->s3StorageId);

            $key = $s3Storage->key;
            $secret = $s3Storage->secret;
            $bucket = $s3Storage->bucket;
            $endpoint = $s3Storage->endpoint;

            // Clean the path
            $cleanPath = ltrim($this->s3Path, '/');

            // Create temporary download directory
            $downloadDir = "/tmp/s3-restore-{$this->resource->uuid}";
            $downloadPath = "{$downloadDir}/".basename($cleanPath);

            // Get helper image
            $helperImage = config('constants.coolify.helper_image');
            $latestVersion = instanceSettings()->helper_version;
            $fullImageName = "{$helperImage}:{$latestVersion}";

            // Prepare download commands
            $commands = [];

            // Create download directory on server
            $commands[] = "mkdir -p {$downloadDir}";

            // Check if container exists and remove it
            $containerName = "s3-restore-{$this->resource->uuid}";
            $containerExists = instant_remote_process(["docker ps -a -q -f name={$containerName}"], $this->server, false);
            if (filled($containerExists)) {
                instant_remote_process(["docker rm -f {$containerName}"], $this->server, false);
            }

            // Run MinIO client container to download file
            $commands[] = "docker run -d --name {$containerName} --rm -v {$downloadDir}:{$downloadDir} {$fullImageName} sleep 30";
            $commands[] = "docker exec {$containerName} mc alias set temporary {$endpoint} {$key} \"{$secret}\"";
            $commands[] = "docker exec {$containerName} mc cp temporary/{$bucket}/{$cleanPath} {$downloadPath}";

            // Execute download commands
            $activity = remote_process($commands, $this->server, ignore_errors: false, callEventOnFinish: 'App\\Events\\S3DownloadFinished', callEventData: [
                'downloadPath' => $downloadPath,
                'containerName' => $containerName,
                'serverId' => $this->server->id,
                'resourceUuid' => $this->resource->uuid,
            ]);

            $this->s3DownloadedFile = $downloadPath;
            $this->filename = $downloadPath;

            $this->dispatch('activityMonitor', $activity->id);
            $this->dispatch('info', 'Downloading file from S3. This may take a few minutes for large backups...');
        } catch (\Throwable $e) {
            $this->s3DownloadInProgress = false;
            $this->s3DownloadedFile = null;

            return handleError($e, $this);
        }
    }

    public function restoreFromS3()
    {
        $this->authorize('update', $this->resource);

        if (! $this->s3DownloadedFile) {
            $this->dispatch('error', 'Please download the file from S3 first.');

            return;
        }

        try {
            $this->importRunning = true;
            $this->importCommands = [];

            // Use the downloaded file path
            $backupFileName = '/tmp/restore_'.$this->resource->uuid;
            $this->importCommands[] = "docker cp {$this->s3DownloadedFile} {$this->container}:{$backupFileName}";
            $tmpPath = $backupFileName;

            // Copy the restore command to a script file
            $scriptPath = "/tmp/restore_{$this->resource->uuid}.sh";

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
            }

            $restoreCommandBase64 = base64_encode($restoreCommand);
            $this->importCommands[] = "echo \"{$restoreCommandBase64}\" | base64 -d > {$scriptPath}";
            $this->importCommands[] = "chmod +x {$scriptPath}";
            $this->importCommands[] = "docker cp {$scriptPath} {$this->container}:{$scriptPath}";

            $this->importCommands[] = "docker exec {$this->container} sh -c '{$scriptPath}'";
            $this->importCommands[] = "docker exec {$this->container} sh -c 'echo \"Import finished with exit code $?\"'";

            if (! empty($this->importCommands)) {
                $activity = remote_process($this->importCommands, $this->server, ignore_errors: true, callEventOnFinish: 'App\\Events\\S3RestoreJobFinished', callEventData: [
                    'scriptPath' => $scriptPath,
                    'tmpPath' => $tmpPath,
                    'container' => $this->container,
                    'serverId' => $this->server->id,
                    's3DownloadedFile' => $this->s3DownloadedFile,
                    'resourceUuid' => $this->resource->uuid,
                ]);
                $this->dispatch('activityMonitor', $activity->id);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->importCommands = [];
        }
    }

    public function cancelS3Download()
    {
        if ($this->s3DownloadedFile) {
            try {
                // Cleanup downloaded file and directory
                $downloadDir = "/tmp/s3-restore-{$this->resource->uuid}";
                instant_remote_process(["rm -rf {$downloadDir}"], $this->server, false);

                // Cleanup container if exists
                $containerName = "s3-restore-{$this->resource->uuid}";
                instant_remote_process(["docker rm -f {$containerName}"], $this->server, false);

                $this->dispatch('success', 'S3 download cancelled and temporary files cleaned up.');
            } catch (\Throwable $e) {
                ray($e);
            }
        }

        // Reset S3 download state
        $this->s3DownloadedFile = null;
        $this->s3DownloadInProgress = false;
        $this->filename = null;
    }
}
