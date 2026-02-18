<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ComposeBackups extends Component
{
    use AuthorizesRequests;

    public ?Application $application = null;

    public ?ServiceDatabase $selectedDatabase = null;

    public string $selectedDatabaseUuid = '';

    public array $parameters;

    public bool $isImportSupported = false;

    protected $listeners = ['refreshScheduledBackups' => '$refresh'];

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            $this->application = Application::whereUuid($this->parameters['application_uuid'])->first();
            if (! $this->application || $this->application->build_pack !== 'dockercompose') {
                return redirect()->route('dashboard');
            }
            $this->authorize('view', $this->application);

            // Auto-select first database if available
            $firstDb = $this->application->composeDatabases()->first();
            if ($firstDb) {
                $this->selectDatabase($firstDb->uuid);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function selectDatabase(string $uuid)
    {
        $this->selectedDatabaseUuid = $uuid;
        $this->selectedDatabase = $this->application->composeDatabases()->whereUuid($uuid)->first();

        if ($this->selectedDatabase) {
            $dbType = $this->selectedDatabase->databaseType();
            $supportedTypes = ['mysql', 'mariadb', 'postgres', 'mongo'];
            $this->isImportSupported = collect($supportedTypes)->contains(fn ($type) => str_contains($dbType, $type));
        }
    }

    public function render()
    {
        return view('livewire.project.application.compose-backups', [
            'composeDatabases' => $this->application->composeDatabases()->get(),
        ]);
    }
}
