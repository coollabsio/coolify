<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DatabaseBackups extends Component
{
    use AuthorizesRequests;

    public ?Application $application = null;

    public ?ServiceDatabase $serviceDatabase = null;

    public $project;

    public $environment;

    public array $parameters;

    protected $listeners = ['refreshScheduledBackups' => '$refresh'];

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();

            $project = currentTeam()
                ->projects()
                ->select('id', 'uuid', 'team_id')
                ->where('uuid', request()->route('project_uuid'))
                ->firstOrFail();
            $environment = $project->environments()
                ->select('id', 'uuid', 'name', 'project_id')
                ->where('uuid', request()->route('environment_uuid'))
                ->firstOrFail();
            $this->application = $environment->applications()
                ->where('uuid', request()->route('application_uuid'))
                ->firstOrFail();

            $this->project = $project;
            $this->environment = $environment;

            $this->authorize('view', $this->application);

            $this->serviceDatabase = $this->application->composeDatabases()
                ->whereUuid($this->parameters['stack_service_uuid'])
                ->first();

            if (! $this->serviceDatabase) {
                return redirect()->route('project.application.configuration', [
                    'project_uuid' => $this->parameters['project_uuid'],
                    'environment_uuid' => $this->parameters['environment_uuid'],
                    'application_uuid' => $this->parameters['application_uuid'],
                ]);
            }

            if (! $this->serviceDatabase->isBackupSolutionAvailable()) {
                return redirect()->route('project.application.configuration', [
                    'project_uuid' => $this->parameters['project_uuid'],
                    'environment_uuid' => $this->parameters['environment_uuid'],
                    'application_uuid' => $this->parameters['application_uuid'],
                ]);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.database-backups');
    }
}
