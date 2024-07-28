<?php

namespace App\Livewire\Tags;

use App\Http\Controllers\Api\DeployController;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Tag;
use Livewire\Component;

class Show extends Component
{
    public $tags;

    public Tag $tag;

    public $applications;

    public $services;

    public $webhook = null;

    public $deployments_per_tag_per_server = [];

    public function mount()
    {
        $this->tags = Tag::ownedByCurrentTeam()->get()->unique('name')->sortBy('name');
        $tag = $this->tags->where('name', request()->tag_name)->first();
        if (! $tag) {
            return redirect()->route('tags.index');
        }
        $this->webhook = generatTagDeployWebhook($tag->name);
        $this->applications = $tag->applications()->get();
        $this->services = $tag->services()->get();
        $this->tag = $tag;
        $this->get_deployments();
    }

    public function get_deployments()
    {
        try {
            $resource_ids = $this->applications->pluck('id');
            $this->deployments_per_tag_per_server = ApplicationDeploymentQueue::whereIn('status', ['in_progress', 'queued'])->whereIn('application_id', $resource_ids)->get([
                'id',
                'application_id',
                'application_name',
                'deployment_url',
                'pull_request_id',
                'server_name',
                'server_id',
                'status',
            ])->sortBy('id')->groupBy('server_name')->toArray();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function redeploy_all()
    {
        try {
            $message = collect([]);
            $this->applications->each(function ($resource) use ($message) {
                $deploy = new DeployController;
                $message->push($deploy->deploy_resource($resource));
            });
            $this->services->each(function ($resource) use ($message) {
                $deploy = new DeployController;
                $message->push($deploy->deploy_resource($resource));
            });
            $this->dispatch('success', 'Mass deployment started.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.tags.show');
    }
}
