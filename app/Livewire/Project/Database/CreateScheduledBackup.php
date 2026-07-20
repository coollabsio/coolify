<?php

namespace App\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackup;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CreateScheduledBackup extends Component
{
    use AuthorizesRequests;

    #[Validate(['required', 'string'])]
    public $frequency;

    #[Locked]
    public $database;

    public bool $enabled = true;

    public function submit()
    {
        try {
            $this->authorize('manageBackups', $this->database);

            if (! $this->database->isBackupSolutionAvailable()) {
                $this->dispatch('error', 'Scheduled backups are not supported for this database type.');

                return;
            }

            $this->validate();

            $isValid = validate_cron_expression($this->frequency);
            if (! $isValid) {
                $this->dispatch('error', 'Invalid Cron / Human expression.');

                return;
            }

            $payload = [
                'enabled' => true,
                'frequency' => $this->frequency,
                'save_s3' => false,
                's3_storage_id' => null,
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
            } elseif ($this->database->type() === 'standalone-clickhouse') {
                $payload['databases_to_backup'] = $this->database->clickhouse_db;
            }

            $databaseBackup = ScheduledDatabaseBackup::create($payload);
            if ($this->database->getMorphClass() === ServiceDatabase::class) {
                $service = $this->database->service;
                $this->redirectRoute('project.service.database.backup.show', [
                    'project_uuid' => $service->project()->uuid,
                    'environment_uuid' => $service->environment->uuid,
                    'service_uuid' => $service->uuid,
                    'stack_service_uuid' => $this->database->uuid,
                    'backup_uuid' => $databaseBackup->uuid,
                ], navigate: true);
            } else {
                $this->redirectRoute('project.database.backup.execution', [
                    'project_uuid' => $this->database->project()->uuid,
                    'environment_uuid' => $this->database->environment->uuid,
                    'database_uuid' => $this->database->uuid,
                    'backup_uuid' => $databaseBackup->uuid,
                ], navigate: true);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->frequency = '';
        }
    }
}
