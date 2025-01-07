<?php

namespace App\Livewire;

use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redirect;
use Livewire\Component;

class Dashboard extends Component
{
    public $projects = [];

    public Collection $servers;

    public Collection $privateKeys;

    public array $deploymentsPerServer = [];

    public function mount()
    {
        $this->privateKeys = PrivateKey::ownedByCurrentTeam()->get();
        $this->servers = Server::ownedByCurrentTeam()->get();
        $this->projects = Project::ownedByCurrentTeam()->get();
        $this->loadDeployments();
    }

    public function cleanupQueue()
    {
        Artisan::queue('cleanup:deployment-queue', [
            '--team-id' => currentTeam()->id,
        ]);
    }

    public function loadDeployments()
    {
        $this->deploymentsPerServer = ApplicationDeploymentQueue::query()->whereIn('status', ['in_progress', 'queued'])->whereIn('server_id', $this->servers->pluck('id'))->get([
            'id',
            'application_id',
            'application_name',
            'deployment_url',
            'pull_request_id',
            'server_name',
            'server_id',
            'status',
        ])->sortBy('id')->groupBy('server_name')->toArray();
    }

    public function navigateToProject($projectUuid)
    {
        $project = Project::query()->where('uuid', $projectUuid)->first();

        if ($project && $project->environments->count() === 1) {
            return Redirect::route('project.resource.index', [
                'project_uuid' => $projectUuid,
                'environment_uuid' => $project->environments->first()->uuid,
            ]);
        }

        return Redirect::route('project.show', ['project_uuid' => $projectUuid]);
    }

    public function render()
    {
        return view('livewire.dashboard');
    }
}
