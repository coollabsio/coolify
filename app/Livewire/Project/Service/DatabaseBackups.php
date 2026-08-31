<?php

namespace App\Livewire\Project\Service;

use App\Models\ScheduledDatabaseBackup;
use App\Models\Service;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DatabaseBackups extends Component
{
    use AuthorizesRequests;

    public ?Service $service = null;

    public ?ServiceDatabase $serviceDatabase = null;

    public array $parameters;

    public array $backupParameters = [];

    public array $query;

    public ?ScheduledDatabaseBackup $backup = null;

    public string $section = 'index';

    public $s3s;

    protected $listeners = ['refreshScheduledBackups' => '$refresh'];

    public function mount(): mixed
    {
        try {
            $this->parameters = array_filter(
                get_route_parameters(),
                fn (string $key): bool => $key !== 'backup_uuid',
                ARRAY_FILTER_USE_KEY,
            );
            $this->query = request()->query();
            $project = currentTeam()
                ->projects()
                ->select('id', 'uuid', 'team_id')
                ->where('uuid', $this->parameters['project_uuid'])
                ->firstOrFail();
            $environment = $project->environments()
                ->select('id', 'uuid', 'name', 'project_id')
                ->where('uuid', $this->parameters['environment_uuid'])
                ->firstOrFail();
            $this->service = $environment->services()->whereUuid($this->parameters['service_uuid'])->firstOrFail();
            $this->authorize('view', $this->service);

            $this->serviceDatabase = $this->service->databases()->whereUuid($this->parameters['stack_service_uuid'])->first();
            if (! $this->serviceDatabase) {
                return redirect()->route('project.service.configuration', [
                    'project_uuid' => $this->parameters['project_uuid'],
                    'environment_uuid' => $this->parameters['environment_uuid'],
                    'service_uuid' => $this->parameters['service_uuid'],
                ]);
            }

            // Check if backups are supported for this database
            if (! $this->serviceDatabase->isBackupSolutionAvailable() && ! $this->serviceDatabase->is_migrated) {
                return redirect()->route('project.service.index', $this->parameters);
            }

            if (! request()->route('backup_uuid')) {
                return redirect()->route('project.service.volume-backups.index', [
                    'project_uuid' => $this->parameters['project_uuid'],
                    'environment_uuid' => $this->parameters['environment_uuid'],
                    'service_uuid' => $this->parameters['service_uuid'],
                ]);
            }

            if (request()->route('backup_uuid')) {
                $this->backup = $this->serviceDatabase->scheduledBackups()
                    ->where('uuid', request()->route('backup_uuid'))
                    ->firstOrFail();
                $this->s3s = currentTeam()->s3s;
                $this->backupParameters = [...$this->parameters, 'backup_uuid' => $this->backup->uuid];
                $this->section = match (request()->route()?->getName()) {
                    'project.service.database.backup.s3' => 's3',
                    'project.service.database.backup.retention' => 'retention',
                    'project.service.database.backup.executions' => 'executions',
                    'project.service.database.backup.danger' => 'danger',
                    default => 'general',
                };

                $routeParameters = [
                    'project_uuid' => $this->parameters['project_uuid'],
                    'environment_uuid' => $this->parameters['environment_uuid'],
                    'service_uuid' => $this->parameters['service_uuid'],
                ];

                return redirect()->route('project.service.volume-backups.index', $routeParameters);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.service.database-backups');
    }
}
