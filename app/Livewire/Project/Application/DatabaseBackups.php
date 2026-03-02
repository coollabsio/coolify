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

    public array $parameters;

    protected $listeners = ['refreshScheduledBackups' => '$refresh'];

    public function mount(): void
    {
        try {
            $this->parameters = get_route_parameters();
            $this->application = Application::whereUuid($this->parameters['application_uuid'])->first();
            if (! $this->application) {
                redirect()->route('dashboard');

                return;
            }
            $this->authorize('view', $this->application);

            if ($this->application->build_pack !== 'dockercompose') {
                redirect()->route('project.application.configuration', $this->parameters);

                return;
            }

            $this->serviceDatabase = $this->application->databases()
                ->whereUuid($this->parameters['database_uuid'])
                ->first();

            if (! $this->serviceDatabase || ! $this->serviceDatabase->isBackupSolutionAvailable()) {
                redirect()->route('project.application.configuration', $this->parameters);

                return;
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.database-backups');
    }
}
