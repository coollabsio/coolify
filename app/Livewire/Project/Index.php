<?php

namespace App\Livewire\Project;

use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Facades\Redirect;
use Livewire\Component;

class Index extends Component
{
    public $projects;

    public $servers;

    public $private_keys;

    public function mount()
    {
        $this->private_keys = PrivateKey::ownedByCurrentTeam()->get();
        $this->projects = Project::ownedByCurrentTeam()->get()->map(function ($project) {
            $project->settingsRoute = route('project.edit', ['project_uuid' => $project->uuid]);

            return $project;
        });
        $this->servers = Server::ownedByCurrentTeam()->count();
    }

    public function render()
    {
        return view('livewire.project.index');
    }

    public function navigateToProject($projectUuid)
    {
        $project = Project::where('uuid', $projectUuid)->first();

        if ($project && $project->environments->count() === 1) {
            return Redirect::route('project.resource.index', [
                'project_uuid' => $projectUuid,
                'environment_uuid' => $project->environments->first()->uuid,
            ]);
        }

        return Redirect::route('project.show', ['project_uuid' => $projectUuid]);
    }
}
