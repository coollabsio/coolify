<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class Configuration extends Component
{
    public $currentRoute;

    public Application $application;

    public $project;

    public $environment;

    public $servers;

    protected $listeners = ['buildPackUpdated' => '$refresh'];

    public function mount()
    {
        $this->currentRoute = request()->route()->getName();

        $project = currentTeam()
            ->projects()
            ->select('id', 'uuid', 'team_id')
            ->where('uuid', request()->route('project_uuid'))
            ->firstOrFail();
        $environment = $project->environments()
            ->select('id', 'uuid', 'name', 'project_id')
            ->where('uuid', request()->route('environment_uuid'))
            ->firstOrFail();
        $application = $environment->applications()
            ->with(['destination'])
            ->where('uuid', request()->route('application_uuid'))
            ->firstOrFail();

        $this->project = $project;
        $this->environment = $environment;
        $this->application = $application;
        if ($this->application->build_pack === 'dockercompose' && $this->currentRoute === 'project.application.healthcheck') {
            return redirect()->route('project.application.configuration', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]);
        }
    }

    public function render()
    {
        return view('livewire.project.application.configuration');
    }
}
