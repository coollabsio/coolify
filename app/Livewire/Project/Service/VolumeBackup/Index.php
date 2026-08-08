<?php

namespace App\Livewire\Project\Service\VolumeBackup;

use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackup;
use App\Models\Service;
use App\Models\ServiceDatabase;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public array $parameters;

    public string $search = '';

    protected $listeners = ['refreshVolumeBackups' => '$refresh'];

    public function mount(): void
    {
        $this->service = $this->findService();
        $this->authorize('view', $this->service);
        $this->parameters = get_route_parameters();
        $this->search = request()->string('search')->toString();
    }

    public function render(): View
    {
        $databaseTargets = $this->service->databases->filter(
            fn (ServiceDatabase $database): bool => $database->isBackupSolutionAvailable(),
        );

        $databaseBackups = ScheduledDatabaseBackup::query()
            ->with(['database', 'latest_log', 's3'])
            ->withCount('executions')
            ->where('database_type', (new ServiceDatabase)->getMorphClass())
            ->whereHasMorph('database', [ServiceDatabase::class], fn ($query) => $query->where('service_id', $this->service->id))
            ->latest()
            ->get();

        $backups = ScheduledVolumeBackup::query()
            ->with(['backupable.resource', 'latestExecution', 's3'])
            ->withCount('executions')
            ->forService($this->service)
            ->latest()
            ->get();

        return view('livewire.project.service.volume-backup.index', [
            'backups' => $backups,
            'databaseBackups' => $databaseBackups,
            'databaseTargets' => $databaseTargets,
        ]);
    }

    private function findService(): Service
    {
        $project = currentTeam()->projects()->where('uuid', request()->route('project_uuid'))->firstOrFail();
        $environment = $project->environments()->where('uuid', request()->route('environment_uuid'))->firstOrFail();

        return $environment->services()
            ->with(['server.settings', 'environment.project'])
            ->where('uuid', request()->route('service_uuid'))
            ->firstOrFail();
    }
}
