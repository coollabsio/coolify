<?php

namespace App\Services;

use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PgBackRestService
{
    private string $containerName;

    private string $stanzaName;

    private string $configPath;

    private string $repoPath;

    public function __construct(
        private StandalonePostgresql $database,
        private ScheduledDatabaseBackup $backup,
        private Server $server
    ) {
        $this->containerName = $this->database->uuid;
        $this->stanzaName = $this->backup->pgbackrest_stanza_name ?? $this->generateStanzaName();
        $this->configPath = database_configuration_dir().'/'.$this->database->uuid.'/pgbackrest';
        $this->repoPath = backup_dir().'/pgbackrest/'.str($this->database->team()->name)->slug().'-'.$this->database->team()->id.'/'.$this->database->uuid;
    }

    /**
     * Check if pgBackRest is installed in the PostgreSQL container
     */
    public function isInstalled(): bool
    {
        try {
            $command = "docker exec {$this->containerName} which pgbackrest";
            $result = instant_remote_process([$command], $this->server, false);

            return ! empty(trim($result)) && ! str_contains($result, 'not found');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Install pgBackRest in the PostgreSQL container
     */
    public function install(): void
    {
        Log::info('Installing pgBackRest', ['database' => $this->database->uuid]);

        $commands = [];

        // Detect OS and install pgBackRest (requires root privileges)
        $commands[] = "docker exec -u root {$this->containerName} sh -c 'if command -v apt-get > /dev/null; then apt-get update && apt-get install -y pgbackrest; elif command -v yum > /dev/null; then yum install -y pgbackrest; elif command -v apk > /dev/null; then apk add --no-cache pgbackrest; else echo \"Unsupported OS\"; exit 1; fi'";

        instant_remote_process($commands, $this->server);

        Log::info('pgBackRest installed successfully', ['database' => $this->database->uuid]);
    }

    /**
     * Generate configuration file for pgBackRest
     */
    public function generateConfig(): string
    {
        $config = "[global]\n";

        // Repository configuration
        if ($this->backup->save_s3 && $this->backup->s3) {
            $config .= $this->generateS3Config();
        } else {
            $config .= $this->generateLocalConfig();
        }

        // Retention policies
        $config .= $this->generateRetentionConfig();

        // Compression and encryption
        $config .= "repo1-cipher-type=aes-256-cbc\n";
        $config .= 'repo1-cipher-pass='.$this->generateCipherPassword()."\n";
        $config .= "compress-type=zst\n";
        $config .= "compress-level=3\n";

        // Process settings
        $config .= "process-max=4\n";
        $config .= "log-level-console=info\n";
        $config .= "log-level-file=detail\n";

        // Stanza configuration
        $config .= "\n[{$this->stanzaName}]\n";
        $config .= "pg1-path=/var/lib/postgresql/data\n";
        $config .= "pg1-port=5432\n";
        $config .= "pg1-socket-path=/var/run/postgresql\n";

        // User-provided overrides
        if ($this->backup->pgbackrest_config) {
            $overrides = json_decode($this->backup->pgbackrest_config, true);
            if (is_array($overrides)) {
                foreach ($overrides as $key => $value) {
                    $config .= "{$key}={$value}\n";
                }
            }
        }

        return $config;
    }

    /**
     * Generate S3 repository configuration
     */
    private function generateS3Config(): string
    {
        $s3 = $this->backup->s3;
        $config = "repo1-type=s3\n";
        $config .= "repo1-s3-bucket={$s3->bucket}\n";
        $config .= "repo1-s3-endpoint={$s3->endpoint}\n";
        $config .= "repo1-s3-region={$s3->region}\n";
        $config .= "repo1-s3-key={$s3->key}\n";
        $config .= "repo1-s3-key-secret={$s3->secret}\n";
        $config .= 'repo1-path=/pgbackrest/'.str($this->database->team()->name)->slug().'-'.$this->database->team()->id.'/'.$this->database->uuid."\n";

        return $config;
    }

    /**
     * Generate local repository configuration
     */
    private function generateLocalConfig(): string
    {
        $config = "repo1-type=posix\n";
        $config .= "repo1-path={$this->repoPath}\n";

        return $config;
    }

    /**
     * Generate retention policy configuration
     */
    private function generateRetentionConfig(): string
    {
        $config = '';

        // Full backup retention
        $fullRetention = $this->backup->database_backup_retention_amount_locally ?? 7;
        $config .= "repo1-retention-full={$fullRetention}\n";

        // Differential backup retention
        $diffRetention = 4; // Keep last 4 differential backups
        $config .= "repo1-retention-diff={$diffRetention}\n";

        // Archive retention (in days)
        $archiveRetention = $this->backup->database_backup_retention_days_locally ?? 7;
        $config .= "repo1-retention-archive={$archiveRetention}\n";
        $config .= "repo1-retention-archive-type=full\n";

        return $config;
    }

    /**
     * Generate a secure cipher password
     */
    private function generateCipherPassword(): string
    {
        return Str::random(32);
    }

    /**
     * Create pgBackRest configuration file in the container
     */
    public function createConfigFile(): void
    {
        $config = $this->generateConfig();

        // Create config directory on host
        $commands = [];
        $commands[] = "mkdir -p {$this->configPath}";

        // Write config file on host
        $configFile = $this->configPath.'/pgbackrest.conf';
        $escapedConfig = str_replace("'", "'\\''", $config);
        $commands[] = "echo '{$escapedConfig}' > {$configFile}";

        // Create config directory in container
        $commands[] = "docker exec {$this->containerName} mkdir -p /etc/pgbackrest";

        // Copy config to container
        $commands[] = "docker cp {$configFile} {$this->containerName}:/etc/pgbackrest/pgbackrest.conf";

        // Set permissions
        $commands[] = "docker exec {$this->containerName} chown postgres:postgres /etc/pgbackrest/pgbackrest.conf";
        $commands[] = "docker exec {$this->containerName} chmod 640 /etc/pgbackrest/pgbackrest.conf";

        instant_remote_process($commands, $this->server);

        Log::info('pgBackRest configuration created', [
            'database' => $this->database->uuid,
            'stanza' => $this->stanzaName,
        ]);
    }

    /**
     * Create pgBackRest stanza
     */
    public function createStanza(): void
    {
        Log::info('Creating pgBackRest stanza', [
            'database' => $this->database->uuid,
            'stanza' => $this->stanzaName,
        ]);

        // Create repository directory if using local storage
        if (! $this->backup->save_s3) {
            $commands = [];
            $commands[] = "mkdir -p {$this->repoPath}";
            instant_remote_process($commands, $this->server);
        }

        // Create stanza
        $command = "docker exec -u postgres {$this->containerName} pgbackrest --stanza={$this->stanzaName} stanza-create";
        $output = instant_remote_process([$command], $this->server);

        // Update backup record
        $this->backup->update([
            'pgbackrest_stanza_created' => true,
            'pgbackrest_stanza_name' => $this->stanzaName,
        ]);

        Log::info('pgBackRest stanza created successfully', [
            'database' => $this->database->uuid,
            'stanza' => $this->stanzaName,
            'output' => $output,
        ]);
    }

    /**
     * Configure WAL archiving in PostgreSQL
     */
    public function configureWalArchiving(): void
    {
        if (! $this->backup->enable_pitr) {
            return;
        }

        Log::info('Configuring WAL archiving', ['database' => $this->database->uuid]);

        $commands = [];

        // Configure archive_mode and archive_command
        $archiveCommand = "pgbackrest --stanza={$this->stanzaName} archive-push %p";
        $commands[] = "docker exec -u postgres {$this->containerName} psql -c \"ALTER SYSTEM SET archive_mode = 'on';\"";
        $commands[] = "docker exec -u postgres {$this->containerName} psql -c \"ALTER SYSTEM SET archive_command = '{$archiveCommand}';\"";
        $commands[] = "docker exec -u postgres {$this->containerName} psql -c \"SELECT pg_reload_conf();\"";

        instant_remote_process($commands, $this->server);

        Log::info('WAL archiving configured', ['database' => $this->database->uuid]);
    }

    /**
     * Perform a backup with pgBackRest
     */
    public function performBackup(string $type = 'incr'): array
    {
        Log::info('Starting pgBackRest backup', [
            'database' => $this->database->uuid,
            'type' => $type,
            'stanza' => $this->stanzaName,
        ]);

        $command = "docker exec -u postgres {$this->containerName} pgbackrest --stanza={$this->stanzaName} --type={$type} backup";
        $output = instant_remote_process([$command], $this->server);

        Log::info('pgBackRest backup completed', [
            'database' => $this->database->uuid,
            'type' => $type,
            'output' => $output,
        ]);

        return [
            'success' => true,
            'output' => $output,
            'type' => $type,
        ];
    }

    /**
     * List all backups for the stanza
     */
    public function listBackups(): array
    {
        $command = "docker exec -u postgres {$this->containerName} pgbackrest --stanza={$this->stanzaName} info --output=json";
        $output = instant_remote_process([$command], $this->server);

        $info = json_decode($output, true);

        if (! $info) {
            return [];
        }

        $backups = [];
        foreach ($info as $stanza) {
            if (isset($stanza['backup'])) {
                foreach ($stanza['backup'] as $backup) {
                    $backups[] = [
                        'label' => $backup['label'] ?? null,
                        'type' => $backup['type'] ?? null,
                        'timestamp' => $backup['timestamp'] ?? null,
                        'database_size' => $backup['database']['size'] ?? 0,
                        'repo_size' => $backup['info']['repository']['size'] ?? 0,
                    ];
                }
            }
        }

        return $backups;
    }

    /**
     * Get detailed information about a specific backup
     */
    public function getBackupInfo(string $backupLabel): array
    {
        $command = "docker exec -u postgres {$this->containerName} pgbackrest --stanza={$this->stanzaName} info --set={$backupLabel} --output=json";
        $output = instant_remote_process([$command], $this->server);

        return json_decode($output, true) ?? [];
    }

    /**
     * Restore from a backup
     */
    public function restore(?string $backupLabel = null, ?string $targetTime = null, bool $delta = false): void
    {
        Log::info('Starting pgBackRest restore', [
            'database' => $this->database->uuid,
            'backup_label' => $backupLabel,
            'target_time' => $targetTime,
            'delta' => $delta,
        ]);

        $command = "docker exec -u postgres {$this->containerName} pgbackrest --stanza={$this->stanzaName} restore";

        if ($backupLabel) {
            $command .= " --set={$backupLabel}";
        }

        if ($targetTime) {
            $command .= " --type=time --target=\"{$targetTime}\"";
        }

        if ($delta) {
            $command .= ' --delta';
        }

        $output = instant_remote_process([$command], $this->server);

        Log::info('pgBackRest restore completed', [
            'database' => $this->database->uuid,
            'output' => $output,
        ]);
    }

    /**
     * Expire old backups based on retention policy
     */
    public function expire(): void
    {
        Log::info('Expiring old backups', [
            'database' => $this->database->uuid,
            'stanza' => $this->stanzaName,
        ]);

        $command = "docker exec -u postgres {$this->containerName} pgbackrest --stanza={$this->stanzaName} expire";
        $output = instant_remote_process([$command], $this->server);

        Log::info('Backup expiration completed', [
            'database' => $this->database->uuid,
            'output' => $output,
        ]);
    }

    /**
     * Determine the appropriate backup type based on schedule and history
     */
    public function determineBackupType(): string
    {
        // User-specified type takes precedence
        if ($this->backup->backup_type) {
            return $this->backup->backup_type;
        }

        // Auto-selection based on schedule
        $backups = $this->listBackups();

        if (empty($backups)) {
            return 'full';
        }

        // Find last full backup
        $lastFull = collect($backups)
            ->where('type', 'full')
            ->sortByDesc(fn ($b) => $b['timestamp']['start'] ?? 0)
            ->first();

        if (! $lastFull) {
            return 'full';
        }

        // If last full backup is older than 7 days, do a full backup
        $lastFullTime = \Carbon\Carbon::createFromTimestamp($lastFull['timestamp']['start']);
        if ($lastFullTime->diffInDays() >= 7) {
            return 'full';
        }

        // Find last differential backup
        $lastDiff = collect($backups)
            ->where('type', 'diff')
            ->sortByDesc(fn ($b) => $b['timestamp']['start'] ?? 0)
            ->first();

        if (! $lastDiff) {
            return 'diff';
        }

        // If last differential backup is older than 1 day, do a differential backup
        $lastDiffTime = \Carbon\Carbon::createFromTimestamp($lastDiff['timestamp']['start']);
        if ($lastDiffTime->diffInDays() >= 1) {
            return 'diff';
        }

        // Otherwise, do an incremental backup
        return 'incr';
    }

    /**
     * Generate a unique stanza name
     */
    private function generateStanzaName(): string
    {
        return 'coolify-'.Str::slug($this->database->name).'-'.$this->database->uuid;
    }

    /**
     * Initialize pgBackRest for the database (one-time setup)
     */
    public function initialize(): void
    {
        // Check if already initialized
        if ($this->backup->pgbackrest_stanza_created) {
            Log::info('pgBackRest already initialized', ['database' => $this->database->uuid]);

            return;
        }

        // Install pgBackRest if not present
        if (! $this->isInstalled()) {
            $this->install();
        }

        // Create configuration file
        $this->createConfigFile();

        // Create stanza
        $this->createStanza();

        // Configure WAL archiving if PITR is enabled
        if ($this->backup->enable_pitr) {
            $this->configureWalArchiving();
        }

        // Note: Initial backup will be performed by the backup job
        // determineBackupType() will return 'full' when no backups exist

        Log::info('pgBackRest initialization completed', ['database' => $this->database->uuid]);
    }
}
