<?php

namespace App\Livewire\Project\Service;

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

    public array $query;

    public bool $isImportSupported = false;

    protected $listeners = ['refreshScheduledBackups' => '$refresh'];

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            $this->query = request()->query();
            $this->service = Service::whereUuid($this->parameters['service_uuid'])->first();
            if (! $this->service) {
                return redirect()->route('dashboard');
            }
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

            // Check if import is supported for this database type
            $dbType = $this->serviceDatabase->databaseType();
            $supportedTypes = ['mysql', 'mariadb', 'postgres', 'mongo'];
            $this->isImportSupported = collect($supportedTypes)->contains(fn ($type) => str_contains($dbType, $type));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.service.database-backups');
    }
}
