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

    public array $parameters;

    public $serviceDatabases = [];

    public ?ServiceDatabase $selectedDatabase = null;

    public bool $isImportSupported = false;

    protected $listeners = ['refreshScheduledBackups' => '$refresh'];

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            $this->application = Application::whereUuid($this->parameters['application_uuid'])->first();
            if (! $this->application) {
                return redirect()->route('dashboard');
            }
            $this->authorize('view', $this->application);

            $this->serviceDatabases = $this->application->serviceDatabases()
                ->where(function ($query) {
                    $query->whereNull('deleted_at');
                })
                ->get();

            if ($this->serviceDatabases->isEmpty()) {
                return redirect()->route('project.application.configuration', $this->parameters);
            }

            $this->selectedDatabase = $this->serviceDatabases->first();
            $this->updateImportSupport();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function selectDatabase($databaseId): void
    {
        $this->selectedDatabase = $this->serviceDatabases->firstWhere('id', $databaseId);
        $this->updateImportSupport();
    }

    private function updateImportSupport(): void
    {
        if ($this->selectedDatabase) {
            $dbType = $this->selectedDatabase->databaseType();
            $supportedTypes = ['mysql', 'mariadb', 'postgres', 'mongo'];
            $this->isImportSupported = collect($supportedTypes)->contains(fn ($type) => str_contains($dbType, $type));
        }
    }

    public function render()
    {
        return view('livewire.project.application.database-backups');
    }
}
