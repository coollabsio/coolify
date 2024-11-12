<?php

namespace App\Livewire\Tags;

use App\Models\ApplicationDeploymentQueue;
use Livewire\Component;

class Deployments extends Component
{
    public $deploymentsPerTagPerServer = [];

    public $resourceIds = [];

    public function render()
    {
        return view('livewire.tags.deployments');
    }

    public function getDeployments()
    {
        try {
            $this->deploymentsPerTagPerServer = ApplicationDeploymentQueue::whereIn('status', ['in_progress', 'queued'])->whereIn('application_id', $this->resourceIds)->get([
                'id',
                'application_id',
                'application_name',
                'deployment_url',
                'pull_request_id',
                'server_name',
                'server_id',
                'status',
            ])->sortBy('id')->groupBy('server_name')->toArray();
            $this->dispatch('deployments', $this->deploymentsPerTagPerServer);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}
