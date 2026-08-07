<?php

namespace App\Livewire\Project\Service\VolumeBackup;

use App\Models\ScheduledVolumeBackup;
use App\Models\Service;
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
        $backups = ScheduledVolumeBackup::query()
            ->with(['backupable.resource', 'latestExecution', 's3'])
            ->withCount('executions')
            ->forService($this->service)
            ->latest()
            ->get();

        return view('livewire.project.service.volume-backup.index', ['backups' => $backups]);
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
