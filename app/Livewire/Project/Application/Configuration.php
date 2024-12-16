<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\Server;
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
            ->select('id', 'name', 'project_id')
            ->where('name', request()->route('environment_name'))
            ->firstOrFail();
        $application = $environment->applications()
            ->with(['destination'])
            ->where('uuid', request()->route('application_uuid'))
            ->firstOrFail();

        $this->project = $project;
        $this->environment = $environment;
        $this->application = $application;
        if ($application->destination && $application->destination->server) {
            $mainServer = $application->destination->server;
            $this->servers = Server::ownedByCurrentTeam()
                ->select('id', 'name')
                ->where('id', '!=', $mainServer->id)
                ->get();
        } else {
            $this->servers = collect();
        }
    }

    public function render()
    {
        return view('livewire.project.application.configuration');
    }
}
