<?php

namespace App\Livewire\Project\Database;

use App\Models\ScheduledDatabaseBackup;
use App\Models\Service;
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
    public $database = null;

    #[Locked]
    public ?Service $service = null;

    public ?string $selectedDatabaseUuid = null;

    public bool $enabled = true;

    public function mount(): void
    {
        if ($this->service) {
            $this->authorize('view', $this->service);
            $this->selectedDatabaseUuid = $this->availableDatabases()->first()?->uuid;
        }
    }

    public function submit()
    {
        try {
            $database = $this->selectedDatabase();
            if (! $database) {
                $this->addError('selectedDatabaseUuid', 'Select a database owned by this service.');

                return;
            }

            $this->authorize('manageBackups', $database);

            if (! $database->isBackupSolutionAvailable()) {
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
                'database_id' => $database->id,
                'database_type' => $database->getMorphClass(),
                'team_id' => currentTeam()->id,
            ];

            if ($database->type() === 'standalone-postgresql') {
                $payload['databases_to_backup'] = $database->postgres_db;
            } elseif ($database->type() === 'standalone-mysql') {
                $payload['databases_to_backup'] = $database->mysql_database;
            } elseif ($database->type() === 'standalone-mariadb') {
                $payload['databases_to_backup'] = $database->mariadb_database;
            } elseif ($database->type() === 'standalone-clickhouse') {
                $payload['databases_to_backup'] = $database->clickhouse_db;
            }

            $databaseBackup = ScheduledDatabaseBackup::create($payload);
            if ($database->getMorphClass() === ServiceDatabase::class) {
                $service = $database->service;
                $this->redirectRoute('project.service.database.backup.show', [
                    'project_uuid' => $service->project()->uuid,
                    'environment_uuid' => $service->environment->uuid,
                    'service_uuid' => $service->uuid,
                    'stack_service_uuid' => $database->uuid,
                    'backup_uuid' => $databaseBackup->uuid,
                ], navigate: true);
            } else {
                $this->redirectRoute('project.database.backup.execution', [
                    'project_uuid' => $database->project()->uuid,
                    'environment_uuid' => $database->environment->uuid,
                    'database_uuid' => $database->uuid,
                    'backup_uuid' => $databaseBackup->uuid,
                ], navigate: true);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->frequency = '';
        }
    }

    public function render()
    {
        return view('livewire.project.database.create-scheduled-backup', [
            'databaseOptions' => $this->availableDatabases()->map(fn (ServiceDatabase $database): array => [
                'value' => $database->uuid,
                'label' => $database->human_name ?: $database->name,
            ])->values()->all(),
        ]);
    }

    private function availableDatabases()
    {
        if (! $this->service) {
            return collect();
        }

        return $this->service->databases->filter(fn (ServiceDatabase $database): bool => $database->isBackupSolutionAvailable());
    }

    private function selectedDatabase()
    {
        if (! $this->service) {
            return $this->database;
        }

        return $this->availableDatabases()->firstWhere('uuid', $this->selectedDatabaseUuid);
    }
}
