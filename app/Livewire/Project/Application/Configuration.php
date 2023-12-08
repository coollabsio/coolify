<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Livewire\Component;

class Configuration extends Component
{
    public Application $application;
    public $servers;
    public function mount()
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (!$project) {
            return $this->redirectRoute('dashboard', navigate: true);
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first()->load(['applications']);
        if (!$environment) {
            return $this->redirectRoute('dashboard', navigate: true);
        }
        $application = $environment->applications->where('uuid', request()->route('application_uuid'))->first();
        if (!$application) {
            return $this->redirectRoute('dashboard', navigate: true);
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
