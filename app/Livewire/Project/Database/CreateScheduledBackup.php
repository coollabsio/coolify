<?php

namespace App\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackup;
use App\Models\StandalonePostgresql;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CreateScheduledBackup extends Component
{
    use AuthorizesRequests;

    #[Validate(['required', 'string'])]
    public $frequency;

    #[Validate(['required', 'boolean'])]
    public bool $saveToS3 = false;

    #[Locked]
    public $database;

    public bool $enabled = true;

    #[Validate(['nullable', 'integer'])]
    public ?int $s3StorageId = null;

    public Collection $definedS3s;

    #[Validate(['required', 'boolean'])]
    public bool $usePgbackrest = false;

    #[Validate(['required', 'string', 'in:full,diff,incr'])]
    public string $pgbackrestBackupType = 'full';

    public bool $pgbackrestAvailable = false;

    public function mount()
    {
        try {
            $this->definedS3s = currentTeam()->s3s;
            if ($this->definedS3s->count() > 0) {
                $this->s3StorageId = $this->definedS3s->first()->id;
            }

            if ($this->database instanceof StandalonePostgresql && $this->database->isPgbackrestEnabled()) {
                $this->pgbackrestAvailable = true;
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('manageBackups', $this->database);

            $this->validate();

            $isValid = validate_cron_expression($this->frequency);
            if (! $isValid) {
                $this->dispatch('error', 'Invalid Cron / Human expression.');

                return;
            }

            $payload = [
                'enabled' => true,
                'frequency' => $this->frequency,
                'save_s3' => $this->saveToS3,
                's3_storage_id' => $this->s3StorageId,
                'database_id' => $this->database->id,
                'database_type' => $this->database->getMorphClass(),
                'team_id' => currentTeam()->id,
                'use_pgbackrest' => $this->usePgbackrest && $this->pgbackrestAvailable,
                'pgbackrest_backup_type' => $this->pgbackrestBackupType,
            ];

            if ($this->database->type() === 'standalone-postgresql') {
                $payload['databases_to_backup'] = $this->database->postgres_db;
            } elseif ($this->database->type() === 'standalone-mysql') {
                $payload['databases_to_backup'] = $this->database->mysql_database;
            } elseif ($this->database->type() === 'standalone-mariadb') {
                $payload['databases_to_backup'] = $this->database->mariadb_database;
            }

            $databaseBackup = ScheduledDatabaseBackup::create($payload);
            if ($this->database->getMorphClass() === \App\Models\ServiceDatabase::class) {
                $this->dispatch('refreshScheduledBackups', $databaseBackup->id);
            } else {
                $this->dispatch('refreshScheduledBackups');
            }

        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->frequency = '';
        }
    }
}
