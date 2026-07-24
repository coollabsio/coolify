<?php

namespace App\Jobs;

use App\Actions\Database\ResolvePostgresqlDataDirectory;
use App\Actions\Database\SelectPostgresqlWalBaseBackupForTargetTime;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\ValidatePostgresqlWalGImage;
use App\Models\PostgresqlWalBackupConfiguration;
use App\Models\PostgresqlWalBackupExecution;
use App\Models\StandalonePostgresql;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;
use Throwable;

class PostgresqlWalRestoreJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $maxExceptions = 3;

    public array $backoff = [30, 60, 120, 240];

    public int $timeout;

    public CarbonImmutable $targetTime;

    public string $executionUuid;

    private ?PostgresqlWalBackupExecution $execution = null;

    private ?StandalonePostgresql $targetDatabase = null;

    private ?string $configurationDirectory = null;

    public function __construct(
        public PostgresqlWalBackupConfiguration $sourceConfiguration,
        CarbonInterface $targetTime,
        public string $name,
        public ?string $description = null,
        ?string $executionUuid = null,
    ) {
        $this->onQueue(deployment_queue());
        $this->timeout = $sourceConfiguration->timeout;
        $this->targetTime = CarbonImmutable::instance($targetTime)->utc();
        $this->executionUuid = $executionUuid ?? new_public_id();
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(PostgresqlWalBaseBackupJob::repositoryLockKey($this->sourceConfiguration->id)))
                ->shared()
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function handle(): void
    {
        $this->sourceConfiguration->refresh()->loadMissing([
            'database.destination.server',
            'database.environment',
            's3',
            'team',
        ]);
        $this->execution = $this->restoreExecution();

        if ($this->execution->status === 'success') {
            return;
        }

        $this->execution->update([
            'status' => 'running',
            'message' => null,
            'target_time' => $this->targetTime,
            'finished_at' => null,
        ]);

        try {
            $this->validateRestoreRequest();
            $sourceDatabase = $this->sourceConfiguration->database;
            $server = $sourceDatabase->destination->server;

            $this->archiveRecentTargetWal($sourceDatabase);
            $backups = $this->listBackups($sourceDatabase);
            $selectedBackup = SelectPostgresqlWalBaseBackupForTargetTime::run($backups, $this->targetTime);
            $backupName = (string) data_get($selectedBackup, 'backup_name');

            $this->targetDatabase = $this->resolveOrCreateTargetDatabase();
            $this->prepareAndFetchBackup($backupName);
            $this->startRestoreContainer();
            $this->waitForPromotion();
            $this->reconcileCredentials();
            $this->restartWithoutSourceCredentials();
            $this->verifyRestoredDatabase();

            $this->targetDatabase->update(['status' => 'running:healthy']);
            $this->targetDatabase->walBackupConfiguration()->update([
                'enabled' => false,
                'status' => 'disabled',
                'last_health_message' => 'Restored from WAL-G with archiving disabled.',
            ]);
            $this->targetDatabase->isConfigurationChanged(save: true);
            $this->execution->update([
                'status' => 'success',
                'message' => 'The PostgreSQL point-in-time restore completed and promoted successfully.',
                'backup_name' => $backupName,
                'restored_database_id' => $this->targetDatabase->id,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->recordAttemptFailure($exception);
            $this->stopTargetContainer();

            throw $exception;
        } finally {
            $this->cleanupTransientSourceCredentials();
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            $this->execution ??= PostgresqlWalBackupExecution::where('uuid', $this->executionUuid)->first();
            if (! $this->execution && PostgresqlWalBackupConfiguration::whereKey($this->sourceConfiguration->id)->exists()) {
                $this->execution = $this->restoreExecution();
            }
            $this->targetDatabase ??= $this->execution?->restoredDatabase;
            $message = $exception instanceof MaxAttemptsExceededException
                ? 'The WAL-G repository remained busy or the restore exceeded its retry limit.'
                : ($exception?->getMessage() ?? 'The PostgreSQL point-in-time restore failed.');

            $this->execution?->update([
                'status' => 'failed',
                'message' => $message,
                'finished_at' => now(),
            ]);
            $this->stopTargetContainer();
            $this->cleanupTransientSourceCredentials();
            $this->deleteFailedTarget();
        } catch (Throwable $cleanupException) {
            Log::channel('scheduled-errors')->error('Failed to clean up a PostgreSQL WAL-G restore', [
                'execution_uuid' => $this->executionUuid,
                'error' => $cleanupException->getMessage(),
            ]);
        }
    }

    private function restoreExecution(): PostgresqlWalBackupExecution
    {
        $execution = PostgresqlWalBackupExecution::where('uuid', $this->executionUuid)->first();

        if ($execution && $execution->postgresql_wal_backup_configuration_id !== $this->sourceConfiguration->id) {
            throw new RuntimeException('The restore execution belongs to a different WAL-G repository.');
        }
        if ($execution && $execution->operation !== 'restore') {
            throw new RuntimeException('The WAL-G execution is not a restore operation.');
        }
        if ($execution?->target_time && ! $execution->target_time->equalTo($this->targetTime)) {
            throw new RuntimeException('The restore execution target time cannot be changed between retries.');
        }

        return $execution ?? $this->sourceConfiguration->executions()->create([
            'uuid' => $this->executionUuid,
            'operation' => 'restore',
            'target_time' => $this->targetTime,
        ]);
    }

    private function validateRestoreRequest(): void
    {
        if ($this->targetTime->isFuture()) {
            $this->execution->update([
                'status' => 'failed',
                'message' => 'The restore target time cannot be in the future.',
                'finished_at' => now(),
            ]);

            throw ValidationException::withMessages([
                'target_time' => 'The restore target time cannot be in the future.',
            ]);
        }
        if (blank($this->name)) {
            throw ValidationException::withMessages([
                'name' => 'A name is required for the restored PostgreSQL database.',
            ]);
        }
        if (! $this->sourceConfiguration->enabled || ! in_array($this->sourceConfiguration->status, ['healthy', 'warning'], true)) {
            throw new RuntimeException('The source WAL-G configuration must be enabled and applied before it can be restored.');
        }
        if (! $this->sourceConfiguration->s3?->isUsable()) {
            throw new RuntimeException('The source WAL-G S3 storage is unavailable or unusable.');
        }

        $sourceDatabase = $this->sourceConfiguration->database;
        if (! $sourceDatabase?->destination?->server) {
            throw new RuntimeException('The source PostgreSQL database or server is unavailable.');
        }
        if (! str((string) $sourceDatabase->status)->startsWith('running')) {
            throw new RuntimeException('The source PostgreSQL database must be running to restore from WAL-G.');
        }

        ValidatePostgresqlWalGImage::run($sourceDatabase->image, $this->sourceConfiguration->postgres_major_version);
    }

    private function archiveRecentTargetWal(StandalonePostgresql $sourceDatabase): void
    {
        $recentWindow = max(300, $this->sourceConfiguration->archive_timeout_seconds * 2);
        if ($this->targetTime->lessThan(now()->subSeconds($recentWindow))) {
            return;
        }

        $container = escapeshellarg($sourceDatabase->uuid);
        $user = escapeshellarg($sourceDatabase->postgres_user);
        $database = escapeshellarg($sourceDatabase->postgres_db);
        $switchCommand = "docker exec {$container} psql --username {$user} --dbname {$database} --tuples-only --no-align --command "
            .escapeshellarg('SELECT pg_walfile_name(pg_switch_wal());');
        $archivedCommand = "docker exec {$container} psql --username {$user} --dbname {$database} --tuples-only --no-align --command "
            .escapeshellarg("SELECT COALESCE(last_archived_wal, '') FROM pg_stat_archiver;");
        $attempts = max(10, min(60, (int) ceil($this->sourceConfiguration->archive_timeout_seconds / 2)));
        $script = implode("\n", [
            'segment="$('.$switchCommand.')"',
            'test -n "$segment"',
            'attempt=0',
            'while [ "$attempt" -lt '.escapeshellarg((string) $attempts).' ]; do',
            '    archived="$('.$archivedCommand.')"',
            '    if [ "$archived" = "$segment" ] || [ "$archived" \> "$segment" ]; then exit 0; fi',
            '    attempt=$((attempt + 1))',
            '    sleep 2',
            'done',
            'echo "The WAL segment required for the restore target was not archived in time." >&2',
            'exit 1',
        ]);

        instant_remote_process(
            [$script],
            $sourceDatabase->destination->server,
            timeout: min(180, $this->timeout),
            disableMultiplexing: true,
        );
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws JsonException
     */
    private function listBackups(StandalonePostgresql $sourceDatabase): array
    {
        $command = 'docker exec '.escapeshellarg($sourceDatabase->uuid)
            .' sh -c '.escapeshellarg('set -a; . /etc/wal-g/env; exec wal-g backup-list --json --detail');
        $output = (string) instant_remote_process(
            [$command],
            $sourceDatabase->destination->server,
            timeout: $this->timeout,
            disableMultiplexing: true,
        );
        $backups = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($backups)) {
            throw new RuntimeException('WAL-G returned an invalid backup list.');
        }

        return $backups;
    }

    private function resolveOrCreateTargetDatabase(): StandalonePostgresql
    {
        if ($this->execution->restored_database_id) {
            $database = StandalonePostgresql::find($this->execution->restored_database_id);
            if ($database) {
                if (! $database->walBackupConfiguration()->exists()) {
                    throw new RuntimeException('The existing restore target is missing its WAL-G configuration.');
                }

                return $database;
            }
        }

        $sourceDatabase = $this->sourceConfiguration->database;

        return DB::transaction(function () use ($sourceDatabase): StandalonePostgresql {
            $database = create_standalone_postgresql(
                environmentId: $sourceDatabase->environment_id,
                destination: $sourceDatabase->destination,
                otherData: [
                    'name' => $this->name,
                    'description' => $this->description,
                    'postgres_user' => $sourceDatabase->postgres_user,
                    'postgres_db' => $sourceDatabase->postgres_db,
                    'postgres_conf' => "listen_addresses = '*'",
                    'status' => 'exited',
                    'is_public' => false,
                    'is_log_drain_enabled' => false,
                ],
                databaseImage: $sourceDatabase->image,
            );
            PostgresqlWalBackupConfiguration::create([
                'team_id' => $this->sourceConfiguration->team_id,
                'standalone_postgresql_id' => $database->id,
                's3_storage_id' => $this->sourceConfiguration->s3_storage_id,
                'enabled' => false,
                'base_backup_frequency' => $this->sourceConfiguration->base_backup_frequency,
                'archive_timeout_seconds' => $this->sourceConfiguration->archive_timeout_seconds,
                'wal_level' => $this->sourceConfiguration->wal_level,
                'retention_full_backups' => $this->sourceConfiguration->retention_full_backups,
                'timeout' => $this->sourceConfiguration->timeout,
                'postgres_major_version' => $this->sourceConfiguration->postgres_major_version,
                'status' => 'disabled',
                'last_health_message' => 'Restore is being prepared with archiving disabled.',
            ]);
            $this->execution->update(['restored_database_id' => $database->id]);

            return $database;
        });
    }

    private function prepareAndFetchBackup(string $backupName): void
    {
        $sourceConfiguration = $this->sourceConfiguration;
        $targetDatabase = $this->targetDatabase->fresh();
        $server = $targetDatabase->destination->server;
        $startAction = StartPostgresql::make();
        $startAction->handle(
            $targetDatabase,
            restoreSourceConfiguration: $sourceConfiguration,
            restoreTargetTime: $this->targetTime,
            startContainer: false,
            execute: false,
        );
        $this->configurationDirectory = $startAction->configuration_dir;
        instant_remote_process(
            $startAction->commands,
            $server,
            timeout: $this->timeout,
            disableMultiplexing: true,
        );

        $storage = $targetDatabase->persistentStorages()->whereNull('host_path')->first();
        if (! $storage) {
            throw new RuntimeException('The restore target does not have a managed PostgreSQL data volume.');
        }

        $dataDirectory = ResolvePostgresqlDataDirectory::run($targetDatabase, populated: false);
        $volumeMount = $storage->name.':'.$storage->mount_path;
        $prepareScript = 'set -eu; mkdir -p "$1"; find "$1" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +; chown 999:999 "$1"; chmod 0700 "$1"';
        $prepareCommand = 'docker run --rm --name '.escapeshellarg('coolify-walg-restore-prepare-'.$this->executionUuid)
            .' --entrypoint sh -v '.escapeshellarg($volumeMount)
            .' '.escapeshellarg($targetDatabase->image)
            .' -c '.escapeshellarg($prepareScript)
            .' sh '.escapeshellarg($dataDirectory);
        $fetchCommand = 'docker run --rm --name '.escapeshellarg('coolify-walg-restore-fetch-'.$this->executionUuid)
            .' --user 999:999 --entrypoint sh'
            .' -v '.escapeshellarg($volumeMount)
            .' -v '.escapeshellarg($this->configurationDirectory.'/wal-g/env:/etc/wal-g/env:ro')
            .' '.escapeshellarg($targetDatabase->image)
            .' -c '.escapeshellarg('set -a; . /etc/wal-g/env; exec wal-g backup-fetch "$1" "$2"')
            .' sh '.escapeshellarg($dataDirectory).' '.escapeshellarg($backupName);
        $signalCommand = 'docker run --rm --name '.escapeshellarg('coolify-walg-restore-signal-'.$this->executionUuid)
            .' --user 999:999 --entrypoint sh -v '.escapeshellarg($volumeMount)
            .' '.escapeshellarg($targetDatabase->image)
            .' -c '.escapeshellarg('touch "$1/recovery.signal"')
            .' sh '.escapeshellarg($dataDirectory);

        instant_remote_process(
            [$prepareCommand, $fetchCommand, $signalCommand],
            $server,
            timeout: $this->timeout,
            disableMultiplexing: true,
        );
    }

    private function startRestoreContainer(): void
    {
        instant_remote_process(
            ['docker compose -f '.escapeshellarg($this->configurationDirectory.'/docker-compose.yml').' up -d'],
            $this->targetDatabase->destination->server,
            timeout: $this->timeout,
            disableMultiplexing: true,
        );
    }

    private function waitForPromotion(): void
    {
        instant_remote_process(
            [$this->databaseReadyScript(requirePromotion: true)],
            $this->targetDatabase->destination->server,
            timeout: $this->timeout,
            disableMultiplexing: true,
        );
    }

    private function reconcileCredentials(): void
    {
        $database = $this->targetDatabase;
        $server = $database->destination->server;
        $localTemporaryPath = tempnam(sys_get_temp_dir(), 'coolify-pitr-credentials-');

        if ($localTemporaryPath === false) {
            throw new RuntimeException('Could not create a temporary PostgreSQL credential reconciliation file.');
        }

        $role = str_replace('"', '""', $database->postgres_user);
        $password = str_replace("'", "''", $database->postgres_password);
        $sql = "ALTER ROLE \"{$role}\" WITH PASSWORD '{$password}';\n";
        $remoteTemporaryPath = $this->configurationDirectory.'/wal-g/.credentials.'.$this->executionUuid.'.sql';
        $containerTemporaryPath = '/tmp/coolify-restore-credentials.sql';

        try {
            if (file_put_contents($localTemporaryPath, $sql) === false || ! chmod($localTemporaryPath, 0600)) {
                throw new RuntimeException('Could not securely write the PostgreSQL credential reconciliation file.');
            }

            instant_scp($localTemporaryPath, $remoteTemporaryPath, $server);
            instant_remote_process([
                'chmod 0600 '.escapeshellarg($remoteTemporaryPath),
                'docker cp '.escapeshellarg($remoteTemporaryPath).' '.escapeshellarg($database->uuid.':'.$containerTemporaryPath),
                'docker exec '.escapeshellarg($database->uuid)
                    .' psql --username '.escapeshellarg($database->postgres_user)
                    .' --dbname '.escapeshellarg($database->postgres_db)
                    .' --file '.escapeshellarg($containerTemporaryPath),
            ], $server, timeout: $this->timeout, disableMultiplexing: true);
        } finally {
            if (is_file($localTemporaryPath)) {
                unlink($localTemporaryPath);
            }
            instant_remote_process([
                'docker exec '.escapeshellarg($database->uuid).' rm -f '.escapeshellarg($containerTemporaryPath).' 2>/dev/null || true',
                'rm -f '.escapeshellarg($remoteTemporaryPath),
            ], $server, false, timeout: 30, disableMultiplexing: true);
        }
    }

    private function restartWithoutSourceCredentials(): void
    {
        $database = $this->targetDatabase->fresh();
        $startAction = StartPostgresql::make();
        $startAction->handle($database, execute: false);
        $this->configurationDirectory = $startAction->configuration_dir;
        instant_remote_process(
            $startAction->commands,
            $database->destination->server,
            timeout: $this->timeout,
            disableMultiplexing: true,
        );
    }

    private function verifyRestoredDatabase(): void
    {
        $server = $this->targetDatabase->destination->server;
        instant_remote_process(
            [$this->databaseReadyScript(requirePromotion: true)],
            $server,
            timeout: $this->timeout,
            disableMultiplexing: true,
        );

        $database = $this->targetDatabase;
        $command = 'docker exec '.escapeshellarg($database->uuid)
            .' psql --username '.escapeshellarg($database->postgres_user)
            .' --dbname '.escapeshellarg($database->postgres_db)
            .' --tuples-only --no-align --field-separator='.escapeshellarg('|')
            .' --command '.escapeshellarg("SELECT current_setting('archive_mode'), pg_is_in_recovery();");
        $output = trim((string) instant_remote_process([$command], $server, timeout: 30));

        if ($output !== 'off|f') {
            throw new RuntimeException('The restored PostgreSQL database did not finish recovery with WAL archiving disabled.');
        }
    }

    private function databaseReadyScript(bool $requirePromotion): string
    {
        $database = $this->targetDatabase;
        $container = escapeshellarg($database->uuid);
        $user = escapeshellarg($database->postgres_user);
        $databaseName = escapeshellarg($database->postgres_db);
        $readyCommand = "docker exec {$container} pg_isready --username {$user} --dbname {$databaseName}";
        $recoveryCommand = "docker exec {$container} psql --username {$user} --dbname {$databaseName} --tuples-only --no-align --command "
            .escapeshellarg('SELECT pg_is_in_recovery();');
        $attempts = max(15, (int) floor(min($this->timeout, 1800) / 2));
        $successCondition = $requirePromotion ? '[ "$recovery" = "f" ]' : '[ -n "$recovery" ]';

        return implode("\n", [
            'attempt=0',
            'while [ "$attempt" -lt '.escapeshellarg((string) $attempts).' ]; do',
            '    if '.$readyCommand.' >/dev/null 2>&1; then',
            '        recovery="$('.$recoveryCommand.')"',
            '        if '.$successCondition.'; then exit 0; fi',
            '    fi',
            '    attempt=$((attempt + 1))',
            '    sleep 2',
            'done',
            'echo "PostgreSQL did not finish point-in-time recovery before the timeout." >&2',
            'exit 1',
        ]);
    }

    private function recordAttemptFailure(Throwable $exception): void
    {
        if (! $this->execution?->exists || $this->execution->status === 'success') {
            return;
        }

        $this->execution->update([
            'message' => 'Restore attempt failed: '.$exception->getMessage(),
        ]);
    }

    private function stopTargetContainer(): void
    {
        $database = $this->targetDatabase ?? $this->execution?->restoredDatabase;
        $server = $database?->destination?->server;
        if (! $database || ! $server) {
            return;
        }

        instant_remote_process(
            ['docker rm -f '.escapeshellarg($database->uuid).' 2>/dev/null || true'],
            $server,
            false,
            timeout: 30,
            disableMultiplexing: true,
        );
    }

    private function cleanupTransientSourceCredentials(): void
    {
        $database = $this->targetDatabase ?? $this->execution?->restoredDatabase;
        $server = $database?->destination?->server;
        if (! $database || ! $server) {
            return;
        }

        $configurationDirectory = $this->configurationDirectory ?? database_configuration_dir().'/'.$database->uuid;
        if (isDev()) {
            $configurationDirectory = '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/databases/'.$database->uuid;
        }
        instant_remote_process(
            ['rm -f '.escapeshellarg($configurationDirectory.'/wal-g/env')],
            $server,
            false,
            timeout: 30,
            disableMultiplexing: true,
        );
    }

    private function deleteFailedTarget(): void
    {
        $database = $this->targetDatabase ?? $this->execution?->restoredDatabase;
        if (! $database) {
            return;
        }

        $database->deleteConfigurations();
        $database->deleteVolumes();
        $database->forceDelete();
        if ($this->execution && PostgresqlWalBackupExecution::whereKey($this->execution->id)->exists()) {
            $this->execution->refresh();
        }
    }
}
