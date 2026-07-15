<?php

namespace App\Livewire\Project\Application\Backup;

use App\Models\Application;
use App\Models\ScheduledVolumeBackup;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public ScheduledVolumeBackup $backup;

    public array $parameters;

    public function mount(): void
    {
        $project = currentTeam()->projects()
            ->where('uuid', request()->route('project_uuid'))
            ->firstOrFail();
        $environment = $project->environments()
            ->where('uuid', request()->route('environment_uuid'))
            ->firstOrFail();
        $this->application = $environment->applications()
            ->with('destination.server')
            ->where('uuid', request()->route('application_uuid'))
            ->firstOrFail();
        $this->authorize('view', $this->application);

        $this->backup = ScheduledVolumeBackup::query()
            ->with('volume')
            ->where('uuid', request()->route('backup_uuid'))
            ->whereIn('local_persistent_volume_id', $this->application->persistentStorages()->pluck('id'))
            ->firstOrFail();
        $this->parameters = get_route_parameters();
    }

    public function render()
    {
        return view('livewire.project.application.backup.show');
    }
}
