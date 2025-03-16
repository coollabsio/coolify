<?php

namespace App\Livewire;

use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
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
        $this->deploymentsPerServer = ApplicationDeploymentQueue::whereIn('status', ['in_progress', 'queued'])->whereIn('server_id', $this->servers->pluck('id'))->get([
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
        return $this->redirect(collect($this->projects)->firstWhere('uuid', $projectUuid)->navigateTo(), true);
    }

    public function render()
    {
        return view('livewire.dashboard');
    }
}
