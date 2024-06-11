<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\Server;
use Livewire\Component;

class Configuration extends Component
{
    public Application $application;

    public $servers;

    protected $listeners = ['buildPackUpdated' => '$refresh'];

    public function mount()
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first()->load(['applications']);
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $application = $environment->applications->where('uuid', request()->route('application_uuid'))->first();
        if (! $application) {
            return redirect()->route('dashboard');
        }
        $this->application = $application;
        $mainServer = $this->application->destination->server;
        $servers = Server::ownedByCurrentTeam()->get();
        $this->servers = $servers->filter(function ($server) use ($mainServer) {
            return $server->id != $mainServer->id;
        });
    }

    public function render()
    {
        return view('livewire.project.application.configuration');
    }
}
