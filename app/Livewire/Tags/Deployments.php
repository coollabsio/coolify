<?php

namespace App\Livewire\Tags;

use App\Models\ApplicationDeploymentQueue;
use Livewire\Component;

class Deployments extends Component
{
    public $deployments_per_tag_per_server = [];

    public $resource_ids = [];

    public function render()
    {
        return view('livewire.tags.deployments');
    }

    public function get_deployments()
    {
        try {
            $this->deployments_per_tag_per_server = ApplicationDeploymentQueue::whereIn('status', ['in_progress', 'queued'])->whereIn('application_id', $this->resource_ids)->get([
                'id',
                'application_id',
                'application_name',
                'deployment_url',
                'pull_request_id',
                'server_name',
                'server_id',
                'status',
            ])->sortBy('id')->groupBy('server_name')->toArray();
            $this->dispatch('deployments', $this->deployments_per_tag_per_server);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}
