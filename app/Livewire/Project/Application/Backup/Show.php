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

    public string $section = 'general';

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
            ->with('backupable')
            ->where('uuid', request()->route('backup_uuid'))
            ->forApplication($this->application)
            ->firstOrFail();
        $this->parameters = get_route_parameters();
        $this->section = match (request()->route()?->getName()) {
            'project.application.backup.s3' => 's3',
            'project.application.backup.retention' => 'retention',
            'project.application.backup.executions' => 'executions',
            'project.application.backup.danger' => 'danger',
            default => 'general',
        };
    }

    public function render()
    {
        return view('livewire.project.application.backup.show');
    }
}
