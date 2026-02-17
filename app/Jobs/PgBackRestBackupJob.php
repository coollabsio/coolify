<?php

namespace App\Jobs;

use App\Events\BackupCreated;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Notifications\Database\BackupFailed;
use App\Notifications\Database\BackupSuccess;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;
use Visus\Cuid2\Cuid2;

class PgBackRestBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $maxExceptions = 1;

    public ?Team $team = null;

    public Server $server;

    public StandalonePostgresql|ServiceDatabase $database;

    public ?string $container_name = null;

    public ?ScheduledDatabaseBackupExecution $backup_log = null;

    public string $backup_status = 'failed';

    public ?string $backup_output = null;

    public ?string $error_output = null;

    public $timeout = 7200;

    public ?string $backup_log_uuid = null;

    public function __construct(public ScheduledDatabaseBackup $backup)
    {
        $this->onQueue('high');
        $this->timeout = $backup->timeout ?? 7200;
    }

    public function handle(): void
    {
        try {
            $this->team = Team::find($this->backup->team_id);
            if (! $this->team) {
                $this->backup->delete();

                return;
            }

            if (data_get($this->backup, 'database_type') === ServiceDatabase::class) {
                $this->database = data_get($this->backup, 'database');
                $this->server = $this->database->service->server;
            } else {
                $this->database = data_get($this->backup, 'database');
                $this->server = $this->database->destination->server;
            }

            if (is_null($this->server)) {
                throw new \Exception('Server not found.');
            }
            if (is_null($this->database)) {
                throw new \Exception('Database not found.');
            }

            BackupCreated::dispatch($this->team->id);

            $status = str(data_get($this->database, 'status'));
            if (! $status->startsWith('running') && $this->database->id !== 0) {
                return;
            }

            if (data_get($this->backup, 'database_type') === ServiceDatabase::class) {
                $serviceUuid = $this->database->service->uuid;
                $this->container_name = "{$this->database->name}-$serviceUuid";
            } else {
                $this->container_name = $this->database->uuid;
            }

            // Generate unique UUID for the backup execution
            $attempts = 0;
            do {
                $this->backup_log_uuid = (string) new Cuid2;
                $exists = ScheduledDatabaseBackupExecution::where('uuid', $this->backup_log_uuid)->exists();
                $attempts++;
                if ($attempts >= 3 && $exists) {
                    throw new \Exception('Unable to generate unique UUID for backup execution after 3 attempts');
                }
            } while ($exists);

            $backupType = $this->backup->pgbackrest_backup_type ?? 'full';
            $stanzaName = 'db';

            $this->backup_log = ScheduledDatabaseBackupExecution::create([
                'uuid' => $this->backup_log_uuid,
                'database_name' => 'pgbackrest-'.$backupType,
                'filename' => 'pgbackrest://'.$stanzaName.'/'.$backupType.'/'.Carbon::now()->timestamp,
                'scheduled_database_backup_id' => $this->backup->id,
                'local_storage_deleted' => false,
            ]);

            $this->ensurePgBackRestInstalled();
            $this->configurePgBackRest($stanzaName);
            $this->runBackup($stanzaName, $backupType);

            $this->backup_log->update([
                'status' => 'success',
                'message' => $this->backup_output,
                'size' => $this->getBackupSize($stanzaName),
            ]);

            $this->team->notify(new BackupSuccess($this->backup, $this->database, 'pgbackrest-'.$backupType));

        } catch (Throwable $e) {
            if ($this->backup_log) {
                $this->backup_log->update([
                    'status' => 'failed',
                    'message' => $this->error_output ?? $e->getMessage(),
                    'size' => 0,
                    'filename' => null,
                ]);
            }
            $this->team?->notify(new BackupFailed($this->backup, $this->database, $this->error_output ?? $e->getMessage(), 'pgbackrest'));

            throw $e;
        } finally {
            if ($this->team) {
                BackupCreated::dispatch($this->team->id);
            }
            if ($this->backup_log) {
                $this->backup_log->update([
                    'finished_at' => Carbon::now()->toImmutable(),
                ]);
            }
        }
    }

    private function ensurePgBackRestInstalled(): void
    {
        try {
            // Check if pgbackrest is already installed in the container
            $check = instant_remote_process(
                ["docker exec {$this->container_name} which pgbackrest 2>/dev/null || echo 'NOT_FOUND'"],
                $this->server,
                false,
                false,
                60,
                disableMultiplexing: true
            );

            if (str_contains(trim($check), 'NOT_FOUND') || blank(trim($check))) {
                // Install pgbackrest inside the running container
                $commands = [
                    "docker exec {$this->container_name} bash -c 'apt-get update -qq && apt-get install -y -qq pgbackrest > /dev/null 2>&1'",
                ];
                instant_remote_process($commands, $this->server, true, false, 300, disableMultiplexing: true);
            }
        } catch (Throwable $e) {
            $this->addToErrorOutput('Failed to install pgBackRest: '.$e->getMessage());
            throw $e;
        }
    }

    private function configurePgBackRest(string $stanzaName): void
    {
        try {
            $pgUser = 'postgres';
            if ($this->database instanceof StandalonePostgresql) {
                $pgUser = $this->database->postgres_user;
            }

            $repoType = $this->backup->pgbackrest_repo_type ?? 'posix';
            $retentionFull = $this->backup->pgbackrest_retention_full ?? 2;
            $retentionDiff = $this->backup->pgbackrest_retention_diff ?? 7;

            // Detect the correct PG data directory
            $pgDataDir = $this->detectPgDataDir();

            // Build pgbackrest.conf
            $config = "[global]\n";
            $config .= "repo1-retention-full={$retentionFull}\n";
            $config .= "repo1-retention-diff={$retentionDiff}\n";
            $config .= "compress-type=lz4\n";
            $config .= "compress-level=6\n";
            $config .= "process-max=2\n";
            $config .= "log-level-console=info\n";
            $config .= "log-level-file=detail\n";
            $config .= "start-fast=y\n";
            $config .= "delta=y\n";

            if ($repoType === 's3') {
                $s3Bucket = $this->backup->pgbackrest_s3_bucket;
                $s3Endpoint = $this->backup->pgbackrest_s3_endpoint;
                $s3Region = $this->backup->pgbackrest_s3_region ?? 'us-east-1';
                $s3Key = $this->backup->pgbackrest_s3_key;
                $s3Secret = $this->backup->pgbackrest_s3_secret;

                if (blank($s3Bucket) || blank($s3Endpoint) || blank($s3Key) || blank($s3Secret)) {
                    throw new \Exception('S3 configuration is incomplete for pgBackRest. Please provide bucket, endpoint, key, and secret.');
                }

                $config .= "repo1-type=s3\n";
                $config .= "repo1-s3-bucket={$s3Bucket}\n";
                $config .= "repo1-s3-endpoint={$s3Endpoint}\n";
                $config .= "repo1-s3-region={$s3Region}\n";
                $config .= "repo1-s3-key={$s3Key}\n";
                $config .= "repo1-s3-key-secret={$s3Secret}\n";
                $config .= "repo1-path=/pgbackrest/{$this->container_name}\n";
                // For MinIO/custom S3 endpoints, disable cert verification by default
                if (str_contains($s3Endpoint, 'http://') || str_contains($s3Endpoint, 'minio')) {
                    $config .= "repo1-s3-uri-style=path\n";
                    $config .= "repo1-storage-verify-tls=n\n";
                }
            } else {
                $config .= "repo1-type=posix\n";
                $config .= "repo1-path=/var/lib/pgbackrest\n";
            }

            $config .= "\n[{$stanzaName}]\n";
            $config .= "pg1-path={$pgDataDir}\n";
            $config .= "pg1-user={$pgUser}\n";

            $configBase64 = base64_encode($config);

            $commands = [
                // Create required directories
                "docker exec {$this->container_name} bash -c 'mkdir -p /etc/pgbackrest /var/lib/pgbackrest /var/log/pgbackrest /var/spool/pgbackrest'",
                // Write configuration
                "docker exec {$this->container_name} bash -c \"echo '{$configBase64}' | base64 -d > /etc/pgbackrest/pgbackrest.conf\"",
                // Set proper ownership
                "docker exec {$this->container_name} bash -c 'chown -R {$pgUser}:{$pgUser} /var/lib/pgbackrest /var/log/pgbackrest /var/spool/pgbackrest /etc/pgbackrest'",
            ];

            instant_remote_process($commands, $this->server, true, false, 60, disableMultiplexing: true);

            // Configure WAL archiving in PostgreSQL if not already set
            $this->configureWalArchiving($stanzaName, $pgUser, $pgDataDir);

            // Create or verify stanza
            $stanzaCheck = instant_remote_process(
                ["docker exec -u {$pgUser} {$this->container_name} pgbackrest --stanza={$stanzaName} stanza-create 2>&1 || true"],
                $this->server,
                false,
                false,
                120,
                disableMultiplexing: true
            );

            $this->addToBackupOutput("Stanza setup: ".trim($stanzaCheck));

        } catch (Throwable $e) {
            $this->addToErrorOutput('Failed to configure pgBackRest: '.$e->getMessage());
            throw $e;
        }
    }

    private function detectPgDataDir(): string
    {
        // Try to detect PG data directory from the running instance
        $result = instant_remote_process(
            ["docker exec {$this->container_name} bash -c \"psql -U postgres -tAc \\\"SHOW data_directory;\\\"\" 2>/dev/null || echo '/var/lib/postgresql/data'"],
            $this->server,
            false,
            false,
            30,
            disableMultiplexing: true
        );

        $dataDir = trim($result);
        if (blank($dataDir) || str_contains($dataDir, 'ERROR')) {
            return '/var/lib/postgresql/data';
        }

        return $dataDir;
    }

    private function configureWalArchiving(string $stanzaName, string $pgUser, string $pgDataDir): void
    {
        // Check if archive_mode is already on
        $archiveMode = instant_remote_process(
            ["docker exec {$this->container_name} bash -c \"psql -U {$pgUser} -tAc \\\"SHOW archive_mode;\\\"\""],
            $this->server,
            false,
            false,
            30,
            disableMultiplexing: true
        );

        if (trim($archiveMode) !== 'on') {
            // We need to set archive_mode and archive_command, which requires a restart
            // Append to postgresql.conf or modify existing settings
            $archiveCommand = "pgbackrest --stanza={$stanzaName} archive-push %p";
            $walSettings = "\\n# pgBackRest WAL archiving\\narchive_mode = on\\narchive_command = '{$archiveCommand}'\\nmax_wal_senders = 3\\nwal_level = replica\\n";

            $commands = [
                // Check if pgbackrest settings already exist and remove them before re-adding
                "docker exec {$this->container_name} bash -c \"sed -i '/# pgBackRest WAL archiving/,/^$/d' {$pgDataDir}/postgresql.conf 2>/dev/null || true\"",
                "docker exec {$this->container_name} bash -c \"sed -i '/archive_command.*pgbackrest/d' {$pgDataDir}/postgresql.conf 2>/dev/null || true\"",
                // Append WAL archiving settings
                "docker exec {$this->container_name} bash -c \"echo -e '{$walSettings}' >> {$pgDataDir}/postgresql.conf\"",
            ];

            instant_remote_process($commands, $this->server, true, false, 60, disableMultiplexing: true);

            // Restart PostgreSQL to apply archive_mode change
            instant_remote_process(
                ["docker restart {$this->container_name}"],
                $this->server,
                true,
                false,
                120,
                disableMultiplexing: true
            );

            // Wait for PostgreSQL to be ready
            $this->waitForPostgres($pgUser);

            $this->addToBackupOutput('WAL archiving enabled and PostgreSQL restarted.');
        }
    }

    private function waitForPostgres(string $pgUser): void
    {
        $maxAttempts = 30;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $result = instant_remote_process(
                ["docker exec {$this->container_name} bash -c \"psql -U {$pgUser} -c 'SELECT 1' 2>/dev/null\" && echo 'READY' || echo 'NOT_READY'"],
                $this->server,
                false,
                false,
                10,
                disableMultiplexing: true
            );
            if (str_contains($result, 'READY')) {
                return;
            }
            sleep(2);
        }
        throw new \Exception('PostgreSQL did not become ready after restart within 60 seconds.');
    }

    private function runBackup(string $stanzaName, string $backupType): void
    {
        try {
            $pgUser = 'postgres';
            if ($this->database instanceof StandalonePostgresql) {
                $pgUser = $this->database->postgres_user;
            }

            $typeFlag = match ($backupType) {
                'full' => '--type=full',
                'diff', 'differential' => '--type=diff',
                'incr', 'incremental' => '--type=incr',
                default => '--type=full',
            };

            $command = "docker exec -u {$pgUser} {$this->container_name} pgbackrest --stanza={$stanzaName} {$typeFlag} backup 2>&1";

            $output = instant_remote_process(
                [$command],
                $this->server,
                true,
                false,
                $this->timeout,
                disableMultiplexing: true
            );

            $this->addToBackupOutput("Backup ({$backupType}) completed:\n".trim($output));

        } catch (Throwable $e) {
            $this->addToErrorOutput('Backup failed: '.$e->getMessage());
            throw $e;
        }
    }

    private function getBackupSize(string $stanzaName): int
    {
        try {
            $pgUser = 'postgres';
            if ($this->database instanceof StandalonePostgresql) {
                $pgUser = $this->database->postgres_user;
            }

            $output = instant_remote_process(
                ["docker exec -u {$pgUser} {$this->container_name} pgbackrest --stanza={$stanzaName} info --output=json 2>/dev/null || echo '[]'"],
                $this->server,
                false,
                false,
                30,
                disableMultiplexing: true
            );

            $info = json_decode(trim($output), true);
            if (is_array($info) && ! empty($info)) {
                $backups = data_get($info, '0.backup', []);
                if (! empty($backups)) {
                    $lastBackup = end($backups);
                    $sizeBytes = data_get($lastBackup, 'info.size', 0);
                    $deltaBytes = data_get($lastBackup, 'info.delta', 0);

                    // Return delta (actual data backed up) or total size
                    return $deltaBytes > 0 ? $deltaBytes : $sizeBytes;
                }
            }

            return 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function addToBackupOutput(string $output): void
    {
        if ($this->backup_output) {
            $this->backup_output = $this->backup_output."\n".$output;
        } else {
            $this->backup_output = $output;
        }
    }

    private function addToErrorOutput(string $output): void
    {
        if ($this->error_output) {
            $this->error_output = $this->error_output."\n".$output;
        } else {
            $this->error_output = $output;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('scheduled-errors')->error('PgBackRest backup permanently failed', [
            'job' => 'PgBackRestBackupJob',
            'backup_id' => $this->backup->uuid,
            'database' => $this->database?->name ?? 'unknown',
            'server' => $this->server?->name ?? 'unknown',
            'total_attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);

        $log = ScheduledDatabaseBackupExecution::where('uuid', $this->backup_log_uuid)->first();

        if ($log) {
            $log->update([
                'status' => 'failed',
                'message' => 'Job permanently failed after '.$this->attempts().' attempts: '.($exception?->getMessage() ?? 'Unknown error'),
                'size' => 0,
                'filename' => null,
                'finished_at' => Carbon::now(),
            ]);
        }

        if ($this->team) {
            $output = $this->backup_output ?? $exception?->getMessage() ?? 'Unknown error';
            $this->team->notify(new BackupFailed($this->backup, $this->database, $output, 'pgbackrest'));
        }
    }
}
