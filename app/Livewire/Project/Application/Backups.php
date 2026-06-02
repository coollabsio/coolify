<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Backups extends Component
{
    use AuthorizesRequests;

    public ?Application $application = null;

    public ?ServiceDatabase $serviceDatabase = null;

    public array $parameters;

    public array $query;

    public bool $isImportSupported = false;

    protected $listeners = ['refreshScheduledBackups' => '$refresh'];

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
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
            $this->application = $environment->applications()
                ->where('uuid', $this->parameters['application_uuid'])
                ->firstOrFail();
            $this->authorize('view', $this->application);

            $this->serviceDatabase = $this->application->serviceDatabase()
                ->whereUuid($this->parameters['stack_service_uuid'])
                ->first();
            if (! $this->serviceDatabase) {
                return redirect()->route('project.application.databases', [
                    'project_uuid' => $this->parameters['project_uuid'],
                    'environment_uuid' => $this->parameters['environment_uuid'],
                    'application_uuid' => $this->parameters['application_uuid'],
                ]);
            }

            if (! $this->serviceDatabase->isBackupSolutionAvailable()) {
                return redirect()->route('project.application.databases', [
                    'project_uuid' => $this->parameters['project_uuid'],
                    'environment_uuid' => $this->parameters['environment_uuid'],
                    'application_uuid' => $this->parameters['application_uuid'],
                ]);
            }

            $dbType = $this->serviceDatabase->databaseType();
            $supportedTypes = ['mysql', 'mariadb', 'postgres', 'mongo'];
            $this->isImportSupported = collect($supportedTypes)->contains(fn ($type) => str_contains($dbType, $type));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.backups');
    }
}
