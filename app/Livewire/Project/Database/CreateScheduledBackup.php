<?php

namespace App\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackup;
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

    #[Validate(['required', 'string', 'in:pg_dump,pgbackrest'])]
    public string $backupEngine = 'pg_dump';

    #[Validate(['required', 'string', 'in:full,diff,incr'])]
    public string $backupType = 'full';

    public Collection $definedS3s;

    public bool $isPostgresql = false;

    public bool $pgbackrestAvailable = false;

    public function mount()
    {
        try {
            $this->definedS3s = currentTeam()->s3s;
            if ($this->definedS3s->count() > 0) {
                $this->s3StorageId = $this->definedS3s->first()->id;
            }
            $this->isPostgresql = $this->database instanceof \App\Models\StandalonePostgresql;
            $this->pgbackrestAvailable = $this->isPostgresql && $this->database->isPgBackRestEnabled();
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

            // Validate pgBackRest is enabled on the database if selected
            if ($this->backupEngine === 'pgbackrest' && ! $this->pgbackrestAvailable) {
                $this->dispatch('error', 'pgBackRest must be enabled and configured on the database first.');

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
                'backup_engine' => $this->backupEngine,
                'backup_type' => $this->backupEngine === 'pgbackrest' ? $this->backupType : 'full',
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
