<?php

namespace App\Livewire\Project\Database\Backup;

use App\Jobs\DatabaseBackupJob;
use App\Jobs\PgBackRestBackupJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\StandalonePostgresql;
use App\Services\PgBackRestService;
use Livewire\Component;

class BackupConfiguration extends Component
{
    public ScheduledDatabaseBackup $backup;
    public $database;
    
    // Form fields
    public bool $use_pgbackrest = false;
    public string $backup_engine = 'pg_dump';
    public bool $enabled = false;
    public string $frequency;
    public bool $save_s3 = false;
    public ?int $s3_storage_id = null;
    public int $number_of_backups_locally = 3;
    public int $backup_retention_days = 7;
    
    // pgBackRest status
    public bool $pgbackrest_available = false;
    public bool $pgbackrest_configured = false;
    public ?string $stanza_name = null;
    
    protected $rules = [
        'backup.enabled' => 'required|boolean',
        'backup.frequency' => 'required|string',
        'backup.save_s3' => 'required|boolean',
        'backup.s3_storage_id' => 'nullable|integer',
        'backup.number_of_backups_locally' => 'required|integer|min:0',
        'backup.backup_retention_days' => 'required|integer|min:0',
        'backup.use_pgbackrest' => 'boolean',
        'backup.backup_engine' => 'required|string|in:pg_dump,pgbackrest',
    ];

    public function mount()
    {
        $this->database = $this->backup->database;
        $this->frequency = $this->backup->frequency;
        $this->enabled = $this->backup->enabled;
        $this->save_s3 = $this->backup->save_s3;
        $this->s3_storage_id = $this->backup->s3_storage_id;
        $this->number_of_backups_locally = $this->backup->number_of_backups_locally;
        $this->backup_retention_days = $this->backup->backup_retention_days;
        $this->use_pgbackrest = $this->backup->use_pgbackrest ?? false;
        $this->backup_engine = $this->backup->backup_engine ?? 'pg_dump';
        
        // Check pgBackRest availability for PostgreSQL databases
        if ($this->database instanceof StandalonePostgresql) {
            $this->checkPgBackRestStatus();
        }
    }

    public function checkPgBackRestStatus()
    {
        try {
            $pgBackRest = new PgBackRestService($this->database);
            $this->pgbackrest_configured = $pgBackRest->isConfigured();
            $this->stanza_name = $pgBackRest->getStanzaName();
            $this->pgbackrest_available = true;
        } catch (\Exception $e) {
            $this->pgbackrest_available = false;
        }
    }

    public function enablePgBackRest()
    {
        try {
            if (!$this->database instanceof StandalonePostgresql) {
                $this->dispatch('error', 'pgBackRest is only available for PostgreSQL databases');
                return;
            }

            $pgBackRest = new PgBackRestService($this->database);
            
            // Install and configure pgBackRest
            $installResult = $pgBackRest->install();
            if (!$installResult['success']) {
                throw new \Exception($installResult['message']);
            }

            $s3Config = null;
            if ($this->save_s3 && $this->s3_storage_id) {
                $s3 = \App\Models\S3Storage::find($this->s3_storage_id);
                $s3Config = [
                    'bucket' => $s3->bucket,
                    'endpoint' => $s3->endpoint,
                    'region' => $s3->region ?? 'us-east-1',
                    'key' => $s3->key,
                    'secret' => $s3->secret,
                ];
            }

            $configResult = $pgBackRest->configure($s3Config);
            if (!$configResult['success']) {
                throw new \Exception($configResult['message']);
            }

            $this->backup_engine = 'pgbackrest';
            $this->use_pgbackrest = true;
            $this->backup->update([
                'backup_engine' => 'pgbackrest',
                'use_pgbackrest' => true,
            ]);

            $this->checkPgBackRestStatus();
            $this->dispatch('success', 'pgBackRest enabled successfully! Your next backup will use incremental backups.');
        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to enable pgBackRest: ' . $e->getMessage());
        }
    }

    public function disablePgBackRest()
    {
        $this->backup_engine = 'pg_dump';
        $this->use_pgbackrest = false;
        $this->backup->update([
            'backup_engine' => 'pg_dump',
            'use_pgbackrest' => false,
        ]);
        $this->dispatch('success', 'Switched back to pg_dump. Existing pgBackRest backups are still available.');
    }

    public function submit()
    {
        try {
            $this->validate();
            
            $this->backup->update([
                'enabled' => $this->enabled,
                'frequency' => $this->frequency,
                'save_s3' => $this->save_s3,
                's3_storage_id' => $this->s3_storage_id,
                'number_of_backups_locally' => $this->number_of_backups_locally,
                'backup_retention_days' => $this->backup_retention_days,
                'backup_engine' => $this->backup_engine,
                'use_pgbackrest' => $this->use_pgbackrest,
            ]);

            $this->dispatch('success', 'Backup configuration updated successfully');
        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to update backup configuration: ' . $e->getMessage());
        }
    }

    public function backupNow()
    {
        try {
            // Dispatch appropriate job based on backup engine
            if ($this->backup->backup_engine === 'pgbackrest') {
                PgBackRestBackupJob::dispatch($this->backup);
                $message = 'pgBackRest backup queued. Incremental backup will be created in a few minutes.';
            } else {
                DatabaseBackupJob::dispatch($this->backup);
                $message = 'Backup queued. It will be available in a few minutes.';
            }
            
            $this->dispatch('success', $message);
        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to start backup: ' . $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.project.database.backup.configuration');
    }
}