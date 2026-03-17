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

    public array $query;

    public bool $isImportSupported = false;

    protected $listeners = ['refreshScheduledBackups' => '$refresh'];

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            $this->query = request()->query();

            $this->application = Application::whereUuid($this->parameters['application_uuid'])->first();
            if (! $this->application) {
                return redirect()->route('dashboard');
            }
            $this->authorize('view', $this->application);

            $this->serviceDatabase = ServiceDatabase::where([
                'application_id' => $this->application->id,
                'name' => $this->parameters['stack_service_uuid'],
            ])->first();

            if (! $this->serviceDatabase) {
                return redirect()->route('project.application.configuration', $this->parameters);
            }

            if (! $this->serviceDatabase->isBackupSolutionAvailable()) {
                return redirect()->route('project.application.configuration', $this->parameters);
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
        return view('livewire.project.application.database-backups');
    }
}

