<?php

namespace App\Livewire\Deployment;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Deployments | Coolify')]
class Index extends Component
{
    #[Url]
    public string $status = 'all';

    public function render()
    {
        $team = auth()->user()->currentTeam();

        $applicationIds = Application::whereHas('environment.project', function ($q) use ($team) {
            $q->where('team_id', $team->id);
        })->pluck('id');

        $query = ApplicationDeploymentQueue::whereIn('application_id', $applicationIds)
            ->orderByDesc('id')
            ->limit(100);

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        $deployments = $query->get([
            'id',
            'application_id',
            'application_name',
            'deployment_url',
            'pull_request_id',
            'server_name',
            'server_id',
            'status',
            'created_at',
        ]);

        return view('livewire.deployment.index', [
            'deployments' => $deployments,
        ]);
    }
}
