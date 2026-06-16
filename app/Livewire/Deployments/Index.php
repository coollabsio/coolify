<?php

namespace App\Livewire\Deployments;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public Collection $deployments;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServiceChecked" => '$refresh',
        ];
    }

    public function mount()
    {
        $this->loadDeployments();
    }

    public function reloadDeployments()
    {
        $this->loadDeployments();
    }

    private function loadDeployments(): void
    {
        $projectIds = currentTeam()->projects()->pluck('id');
        $environmentIds = Environment::whereIn('project_id', $projectIds)->pluck('id');
        $applicationIds = Application::whereIn('environment_id', $environmentIds)->pluck('id');

        $this->deployments = ApplicationDeploymentQueue::whereIn('application_id', $applicationIds)
            ->with(['application.environment.project', 'application.destination.server'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    public function render()
    {
        return view('livewire.deployments.index');
    }
}
