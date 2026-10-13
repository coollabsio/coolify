<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use App\Models\ServiceDatabase;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class ImportBackup extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public Collection $databases;

    public ?ServiceDatabase $selectedDatabase = null;

    public string $selectedDatabaseUuid = '';

    public array $parameters;

    public function mount(): mixed
    {
        $this->parameters = get_route_parameters();
        $project = currentTeam()->projects()->whereUuid($this->parameters['project_uuid'])->firstOrFail();
        $environment = $project->environments()->whereUuid($this->parameters['environment_uuid'])->firstOrFail();
        $this->service = $environment->services()->whereUuid($this->parameters['service_uuid'])->firstOrFail();
        $this->authorize('update', $this->service);

        $this->databases = $this->service->databases
            ->filter(fn (ServiceDatabase $database): bool => $this->supportsImport($database))
            ->values();

        $databaseUuid = request()->route('stack_service_uuid');
        if ($databaseUuid) {
            $selectedDatabase = $this->databases->firstWhere('uuid', $databaseUuid);
            abort_unless($selectedDatabase instanceof ServiceDatabase, 404);
            $this->authorize('update', $selectedDatabase);
            $this->selectedDatabase = $selectedDatabase;
            $this->selectedDatabaseUuid = $selectedDatabase->uuid;

            if (request()->routeIs('project.service.database.import')) {
                return redirect()->route('project.service.import-backup.database', $this->parameters);
            }
        } elseif ($this->databases->count() === 1) {
            return redirect()->route('project.service.import-backup.database', [
                ...$this->parameters,
                'stack_service_uuid' => $this->databases->first()->uuid,
            ]);
        }

        return null;
    }

    public function updatedSelectedDatabaseUuid(): mixed
    {
        $database = $this->databases->firstWhere('uuid', $this->selectedDatabaseUuid);
        abort_unless($database instanceof ServiceDatabase, 404);
        $this->authorize('update', $database);

        return redirect()->route('project.service.import-backup.database', [
            ...$this->parameters,
            'stack_service_uuid' => $database->uuid,
        ]);
    }

    public function render(): View
    {
        return view('livewire.project.service.import-backup');
    }

    private function supportsImport(ServiceDatabase $database): bool
    {
        return str($database->databaseType())->contains(['mysql', 'mariadb', 'postgres', 'mongo']);
    }
}
