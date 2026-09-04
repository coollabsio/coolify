<?php

namespace App\Livewire\Project\Service\VolumeBackup;

use App\Models\ScheduledVolumeBackup;
use App\Models\Service;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public ScheduledVolumeBackup $backup;

    public array $parameters;

    public string $section = 'general';

    public function mount(): mixed
    {
        $project = currentTeam()->projects()->where('uuid', request()->route('project_uuid'))->firstOrFail();
        $environment = $project->environments()->where('uuid', request()->route('environment_uuid'))->firstOrFail();
        $this->service = $environment->services()
            ->with(['server.settings', 'environment.project'])
            ->where('uuid', request()->route('service_uuid'))
            ->firstOrFail();
        $this->authorize('view', $this->service);

        $this->backup = ScheduledVolumeBackup::query()
            ->with('backupable.resource')
            ->where('uuid', request()->route('backup_uuid'))
            ->forService($this->service)
            ->firstOrFail();
        $this->parameters = get_route_parameters();
        $this->section = match (request()->route()?->getName()) {
            'project.service.volume-backups.s3' => 's3',
            'project.service.volume-backups.retention' => 'retention',
            'project.service.volume-backups.executions' => 'executions',
            'project.service.volume-backups.danger' => 'danger',
            default => 'general',
        };

        $routeParameters = collect($this->parameters)->except('backup_uuid')->all();

        return redirect()->route('project.service.volume-backups.index', $routeParameters);
    }

    public function render(): View
    {
        return view('livewire.project.service.volume-backup.show');
    }
}
