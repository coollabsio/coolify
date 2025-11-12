<?php

namespace App\Services;

use App\Models\StandalonePostgresql;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for managing pgBackRest operations
 */
class PgBackRestService
{
    protected $database;
    protected $server;
    protected $stanzaName;
    protected $configPath = '/etc/pgbackrest';
    
    public function __construct(StandalonePostgresql $database)
    {
        $this->database = $database;
        $this->server = $database->destination->server;
        $this->stanzaName = 'db-' . $database->uuid;
    }
    
    /**
     * Install pgBackRest on the database container
     */
    public function install(): array
    {
        try {
            $containerName = $this->database->container_name ?? $this->database->uuid;
            
            // Check if already installed
            $checkCommand = "docker exec {$containerName} which pgbackrest";
            $output = instant_remote_process([$checkCommand], $this->server, false);
            
            if (str_contains($output, 'pgbackrest')) {
                return ['success' => true, 'message' => 'pgBackRest already installed'];
            }
            
            // Install pgBackRest in the container
            $installCommands = [
                "docker exec {$containerName} bash -c 'apt-get update && apt-get install -y wget'",
                "docker exec {$containerName} bash -c 'wget -qO - https://apt.postgresql.org/pub/repos/apt/ACCC4CF8.asc | apt-key add -'",
                "docker exec {$containerName} bash -c 'apt-get update && apt-get install -y pgbackrest'",
            ];
            
            foreach ($installCommands as $command) {
                instant_remote_process([$command], $this->server);
            }
            
            return ['success' => true, 'message' => 'pgBackRest installed successfully'];
        } catch (\Exception $e) {
            Log::error('Failed to install pgBackRest', [
                'database' => $this->database->uuid,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Configure pgBackRest for the database
     */
    public function configure(array $s3Config = null): array
    {
        try {
            $containerName = $this->database->uuid;
            $configContent = $this->generateConfig($s3Config);
            
            // Create config directory in container
            $commands = [
                "docker exec {$containerName} mkdir -p {$this->configPath}",
                "docker exec {$containerName} bash -c 'cat > {$this->configPath}/pgbackrest.conf <<EOF
{$configContent}
EOF'",
                "docker exec {$containerName} chmod 640 {$this->configPath}/pgbackrest.conf",
            ];
            
            foreach ($commands as $command) {
                instant_remote_process([$command], $this->server);
            }
            
            // Configure PostgreSQL for WAL archiving
            $this->configureWalArchiving();
            
            // Create stanza
            $this->createStanza();
            
            return ['success' => true, 'message' => 'pgBackRest configured successfully'];
        } catch (\Exception $e) {
            Log::error('Failed to configure pgBackRest', [
                'database' => $this->database->uuid,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Generate pgBackRest configuration
     */
    protected function generateConfig(array $s3Config = null): string
    {
        $dataDir = '/var/lib/postgresql/data';
        
        $config = "[global]
repo1-retention-full=2
repo1-retention-diff=4
repo1-retention-archive=7
process-max=2
log-level-console=info
log-level-file=debug
start-fast=y

";
        
        // S3 repository configuration
        if ($s3Config) {
            $config .= "repo1-type=s3
repo1-path=/{$s3Config['bucket']}/pgbackrest
repo1-s3-bucket={$s3Config['bucket']}
repo1-s3-endpoint={$s3Config['endpoint']}
repo1-s3-key={$s3Config['key']}
repo1-s3-key-secret={$s3Config['secret']}
repo1-s3-region={$s3Config['region']}
repo1-cipher-type=aes-256-cbc
repo1-cipher-pass=" . Str::random(32) . "

";
        } else {
            // Local repository
            $config .= "repo1-type=posix
repo1-path=/var/lib/pgbackrest
repo1-cipher-type=aes-256-cbc
repo1-cipher-pass=" . Str::random(32) . "

";
        }
        
        // Stanza configuration
        $config .= "[{$this->stanzaName}]
pg1-path={$dataDir}
pg1-port=5432
pg1-socket-path=/var/run/postgresql
";
        
        return $config;
    }
    
    /**
     * Configure PostgreSQL for WAL archiving
     */
    protected function configureWalArchiving(): void
    {
        $containerName = $this->database->uuid;
        
        $walConfig = "
# pgBackRest WAL archiving
wal_level = replica
archive_mode = on
archive_command = 'pgbackrest --stanza={$this->stanzaName} archive-push %p'
max_wal_senders = 3
wal_keep_size = 1GB
";
        
        $commands = [
            "docker exec {$containerName} bash -c 'echo \"{$walConfig}\" >> /var/lib/postgresql/data/postgresql.conf'",
            "docker exec {$containerName} pg_ctl reload -D /var/lib/postgresql/data",
        ];
        
        foreach ($commands as $command) {
            instant_remote_process([$command], $this->server);
        }
    }
    
    /**
     * Create pgBackRest stanza
     */
    protected function createStanza(): array
    {
        try {
            $containerName = $this->database->uuid;
            $command = "docker exec {$containerName} pgbackrest --stanza={$this->stanzaName} stanza-create";
            
            $output = instant_remote_process([$command], $this->server);
            
            return ['success' => true, 'output' => $output];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Perform a backup
     * 
     * @param string $type 'full', 'diff', or 'incr'
     */
    public function backup(string $type = 'incr'): array
    {
        try {
            $containerName = $this->database->uuid;
            $validTypes = ['full', 'diff', 'incr'];
            
            if (!in_array($type, $validTypes)) {
                throw new \InvalidArgumentException("Invalid backup type: {$type}");
            }
            
            $command = "docker exec {$containerName} pgbackrest --stanza={$this->stanzaName} --type={$type} backup";
            
            Log::info('Starting pgBackRest backup', [
                'database' => $this->database->uuid,
                'type' => $type
            ]);
            
            $startTime = microtime(true);
            $output = instant_remote_process([$command], $this->server);
            $duration = round(microtime(true) - $startTime, 2);
            
            // Get backup info
            $info = $this->getBackupInfo();
            $latestBackup = $info['backups'][0] ?? null;
            
            Log::info('pgBackRest backup completed', [
                'database' => $this->database->uuid,
                'type' => $type,
                'duration' => $duration,
                'label' => $latestBackup['label'] ?? null
            ]);
            
            return [
                'success' => true,
                'type' => $type,
                'duration' => $duration,
                'output' => $output,
                'backup' => $latestBackup
            ];
        } catch (\Exception $e) {
            Log::error('pgBackRest backup failed', [
                'database' => $this->database->uuid,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get information about backups
     */
    public function getBackupInfo(): array
    {
        try {
            $containerName = $this->database->uuid;
            $command = "docker exec {$containerName} pgbackrest --stanza={$this->stanzaName} --output=json info";
            
            $output = instant_remote_process([$command], $this->server);
            $info = json_decode($output, true);
            
            if (!$info) {
                return ['backups' => []];
            }
            
            // Parse backup information
            $stanzaInfo = $info[0] ?? [];
            $backups = [];
            
            foreach ($stanzaInfo['backup'] ?? [] as $backup) {
                $backups[] = [
                    'label' => $backup['label'],
                    'type' => $backup['type'],
                    'timestamp' => strtotime($backup['timestamp']['stop']),
                    'database_size' => $backup['info']['size'] ?? 0,
                    'backup_size' => $backup['info']['delta'] ?? 0,
                    'duration' => $backup['info']['time']['elapsed'] ?? 0,
                ];
            }
            
            return ['backups' => $backups];
        } catch (\Exception $e) {
            Log::error('Failed to get backup info', [
                'database' => $this->database->uuid,
                'error' => $e->getMessage()
            ]);
            return ['backups' => []];
        }
    }
    
    /**
     * Restore from a backup
     * 
     * @param string|null $backupLabel Specific backup label, or null for latest
     * @param string|null $targetTime Point-in-time recovery timestamp
     */
    public function restore(?string $backupLabel = null, ?string $targetTime = null): array
    {
        try {
            $containerName = $this->database->uuid;
            
            // Stop PostgreSQL
            instant_remote_process([
                "docker exec {$containerName} pg_ctl stop -D /var/lib/postgresql/data"
            ], $this->server, false);
            
            // Build restore command
            $command = "docker exec {$containerName} pgbackrest --stanza={$this->stanzaName} --delta restore";
            
            if ($backupLabel) {
                $command .= " --set={$backupLabel}";
            }
            
            if ($targetTime) {
                $command .= " --type=time --target='{$targetTime}'";
            }
            
            Log::info('Starting pgBackRest restore', [
                'database' => $this->database->uuid,
                'backup_label' => $backupLabel,
                'target_time' => $targetTime
            ]);
            
            $output = instant_remote_process([$command], $this->server);
            
            // Start PostgreSQL
            instant_remote_process([
                "docker exec {$containerName} pg_ctl start -D /var/lib/postgresql/data"
            ], $this->server);
            
            Log::info('pgBackRest restore completed', [
                'database' => $this->database->uuid
            ]);
            
            return [
                'success' => true,
                'output' => $output
            ];
        } catch (\Exception $e) {
            Log::error('pgBackRest restore failed', [
                'database' => $this->database->uuid,
                'error' => $e->getMessage()
            ]);
            
            // Try to restart PostgreSQL anyway
            try {
                instant_remote_process([
                    "docker exec {$containerName} pg_ctl start -D /var/lib/postgresql/data"
                ], $this->server, false);
            } catch (\Exception $restartException) {
                // Log but don't throw
            }
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get PostgreSQL version
     */
    protected function getPgVersion(): int
    {
        $containerName = $this->database->uuid;
        $command = "docker exec {$containerName} psql --version";
        $output = instant_remote_process([$command], $this->server);
        
        preg_match('/(\d+)\./', $output, $matches);
        return (int)($matches[1] ?? 14);
    }
    
    /**
     * Check if pgBackRest is configured for this database
     */
    public function isConfigured(): bool
    {
        try {
            $containerName = $this->database->uuid;
            $command = "docker exec {$containerName} test -f {$this->configPath}/pgbackrest.conf && echo 'exists'";
            $output = instant_remote_process([$command], $this->server, false);
            
            return str_contains($output, 'exists');
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Get stanza name
     */
    public function getStanzaName(): string
    {
        return $this->stanzaName;
    }
}