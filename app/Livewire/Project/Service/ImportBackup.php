<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use App\Models\ServiceDatabase;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Content of the "Import backup" modal on the service backups page: pick a database, then restore into it.
 */
class ImportBackup extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Service $service;

    public string $selectedDatabaseUuid = '';

    public function mount(Service $service): void
    {
        $this->service = $service;
        $this->authorize('update', $this->service);

        if ($this->databases->count() === 1) {
            $this->selectedDatabaseUuid = $this->databases->first()->uuid;
        }
    }

    /**
     * @return Collection<int, ServiceDatabase>
     */
    #[Computed]
    public function databases(): Collection
    {
        return $this->service->databases
            ->filter(fn (ServiceDatabase $database): bool => str($database->databaseType())->contains(['mysql', 'mariadb', 'postgres', 'mongo']))
            ->values();
    }

    #[Computed]
    public function selectedDatabase(): ?ServiceDatabase
    {
        $database = $this->databases->firstWhere('uuid', $this->selectedDatabaseUuid);
        if (! $database instanceof ServiceDatabase) {
            return null;
        }
        $this->authorize('update', $database);

        return $database;
    }

    public function render(): View
    {
        return view('livewire.project.service.import-backup');
    }
}
