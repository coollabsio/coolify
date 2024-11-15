<?php

namespace App\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackup;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CreateScheduledBackup extends Component
{
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

    public function mount()
    {
        try {
            $this->definedS3s = currentTeam()->s3s;
            if ($this->definedS3s->count() > 0) {
                $this->s3StorageId = $this->definedS3s->first()->id;
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
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
