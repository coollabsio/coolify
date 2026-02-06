<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class Backups extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public Collection $databases;

    public ?ServiceDatabase $selectedDatabase = null;

    public ?string $selectedDatabaseUuid = null;

    public array $parameters = [];

    protected $queryString = ['selectedDatabaseUuid'];

    public function mount(): void
    {
        $this->authorize('view', $this->application);

        $this->parameters = get_route_parameters();

        $this->databases = $this->application->serviceDatabases()->orderBy('name')->get();
        if ($this->databases->isEmpty()) {
            // Ensure compose databases are detected and persisted.
            // This is idempotent and keeps the Backups UI in sync with the compose file.
            try {
                if ($this->application->build_pack === 'dockercompose' && filled($this->application->docker_compose_raw)) {
                    $this->application->parse();
                }
            } catch (\Throwable) {
                // Parsing errors are shown on the General page; backups can still render with whatever is available.
            }
            $this->databases = $this->application->serviceDatabases()->orderBy('name')->get();
        }

        if (! $this->selectedDatabaseUuid && $this->databases->isNotEmpty()) {
            $this->selectedDatabaseUuid = $this->databases->first()->uuid;
        }

        $this->selectedDatabase = $this->databases->firstWhere('uuid', $this->selectedDatabaseUuid)
            ?? $this->databases->first();
    }

    public function selectDatabase(string $uuid): void
    {
        $this->selectedDatabaseUuid = $uuid;
        $this->selectedDatabase = $this->databases->firstWhere('uuid', $uuid);
    }

    public function render()
    {
        return view('livewire.project.application.backups');
    }
}
