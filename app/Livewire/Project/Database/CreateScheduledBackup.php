<?php

namespace App\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackup;
use App\Models\ServiceDatabase;
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

    #[Validate(['required', 'string', 'in:dump,pgbackrest'])]
    public string $backupMethod = 'dump';

    #[Validate(['required', 'string', 'in:full,diff,incr'])]
    public string $pgBackRestBackupType = 'incr';

    #[Validate(['required', 'boolean'])]
    public bool $pgBackRestRequireWalArchive = true;

    #[Locked]
    public $database;

    public bool $enabled = true;

    public bool $isPostgres = false;

    #[Validate(['nullable', 'integer'])]
    public ?int $s3StorageId = null;

    public Collection $definedS3s;

    public function mount()
    {
        try {
            $this->definedS3s = currentTeam()->s3s;
            if ($this->definedS3s->count() > 0) {
                $this->s3StorageId = $this->definedS3s->first()->id;
            }
            $this->isPostgres = $this->isPostgresDatabase();
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

            if ($this->backupMethod === 'pgbackrest') {
                if (! $this->isPostgresDatabase()) {
                    $this->dispatch('error', 'pgBackRest backups are only supported for PostgreSQL databases.');

                    return;
                }
                if (! $this->saveToS3 || blank($this->s3StorageId)) {
                    $this->dispatch('error', 'pgBackRest backups require S3 storage.');

                    return;
                }
            }

            if (filled($this->s3StorageId) && ! $this->selectedS3StorageBelongsToCurrentTeam()) {
                $this->dispatch('error', 'The selected S3 storage is invalid for this team.');

                return;
            }

            $payload = [
                'enabled' => true,
                'frequency' => $this->frequency,
                'save_s3' => $this->saveToS3,
                'backup_method' => $this->backupMethod,
                'pgbackrest_backup_type' => $this->pgBackRestBackupType,
                'pgbackrest_require_wal_archive' => $this->pgBackRestRequireWalArchive,
                's3_storage_id' => $this->s3StorageId,
                'database_id' => $this->database->id,
                'database_type' => $this->database->getMorphClass(),
                'team_id' => currentTeam()->id,
            ];

            if ($this->backupMethod === 'pgbackrest') {
                $payload['dump_all'] = true;
                $payload['databases_to_backup'] = null;
                $payload['disable_local_backup'] = true;
            } elseif ($this->database->type() === 'standalone-postgresql') {
                $payload['databases_to_backup'] = $this->database->postgres_db;
            } elseif ($this->database->type() === 'standalone-mysql') {
                $payload['databases_to_backup'] = $this->database->mysql_database;
            } elseif ($this->database->type() === 'standalone-mariadb') {
                $payload['databases_to_backup'] = $this->database->mariadb_database;
            }

            $databaseBackup = ScheduledDatabaseBackup::create($payload);
            if ($this->database->getMorphClass() === ServiceDatabase::class) {
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

    public function updatedBackupMethod(string $value): void
    {
        if ($value === 'pgbackrest') {
            $this->saveToS3 = true;
            if (blank($this->s3StorageId) && $this->definedS3s->count() > 0) {
                $this->s3StorageId = $this->definedS3s->first()->id;
            }
        }
    }

    public function updatedSaveToS3(bool $value): void
    {
        if ($this->backupMethod === 'pgbackrest' && ! $value) {
            $this->saveToS3 = true;
        }
    }

    private function selectedS3StorageBelongsToCurrentTeam(): bool
    {
        if (blank($this->s3StorageId)) {
            return false;
        }

        return currentTeam()->s3s()->whereKey($this->s3StorageId)->exists();
    }

    private function isPostgresDatabase(): bool
    {
        $databaseType = $this->database instanceof ServiceDatabase
            ? $this->database->databaseType()
            : $this->database->type();

        return str($databaseType)->contains('postgres');
    }
}
