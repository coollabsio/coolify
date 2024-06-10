<?php

namespace App\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackup;
use Illuminate\Support\Collection;
use Livewire\Component;

class CreateScheduledBackup extends Component
{
    public $database;

    public $frequency;

    public bool $enabled = true;

    public bool $save_s3 = false;

    public $s3_storage_id;

    public Collection $s3s;

    protected $rules = [
        'frequency' => 'required|string',
        'save_s3' => 'required|boolean',
    ];

    protected $validationAttributes = [
        'frequency' => 'Backup Frequency',
        'save_s3' => 'Save to S3',
    ];

    public function mount()
    {
        $this->s3s = currentTeam()->s3s;
        if ($this->s3s->count() > 0) {
            $this->s3_storage_id = $this->s3s->first()->id;
        }
    }

    public function submit(): void
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
                'save_s3' => $this->save_s3,
                's3_storage_id' => $this->s3_storage_id,
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
            if ($this->database->getMorphClass() === 'App\Models\ServiceDatabase') {
                $this->dispatch('refreshScheduledBackups', $databaseBackup->id);
            } else {
                $this->dispatch('refreshScheduledBackups');
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        } finally {
            $this->frequency = '';
            $this->save_s3 = true;
        }
    }
}
