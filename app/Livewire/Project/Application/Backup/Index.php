<?php

namespace App\Livewire\Project\Application\Backup;

use App\Models\Application;
use App\Models\ScheduledVolumeBackup;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public array $parameters;

    protected $listeners = ['refreshVolumeBackups' => '$refresh'];

    public function mount(): void
    {
        $this->application = $this->findApplication();
        $this->authorize('view', $this->application);
        $this->parameters = get_route_parameters();
    }

    public function render(): View
    {
        $volumeIds = $this->application->persistentStorages()->pluck('id');
        $backups = ScheduledVolumeBackup::query()
            ->with(['volume', 'latestExecution'])
            ->withCount('executions')
            ->whereIn('local_persistent_volume_id', $volumeIds)
            ->latest()
            ->get();

        return view('livewire.project.application.backup.index', ['backups' => $backups]);
    }

    private function findApplication(): Application
    {
        $project = currentTeam()->projects()
            ->where('uuid', request()->route('project_uuid'))
            ->firstOrFail();
        $environment = $project->environments()
            ->where('uuid', request()->route('environment_uuid'))
            ->firstOrFail();

        return $environment->applications()
            ->with('destination.server')
            ->where('uuid', request()->route('application_uuid'))
            ->firstOrFail();
    }
}
