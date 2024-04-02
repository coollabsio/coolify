<?php

namespace App\Livewire\Tags;

use App\Http\Controllers\Api\Deploy;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Tag;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    #[Url()]
    public ?string $tag = null;

    public Collection $tags;
    public Collection $applications;
    public Collection $services;
    public $webhook = null;
    public $deployments_per_tag_per_server = [];

    public function updatedTag()
    {
        $tag = $this->tags->where('name', $this->tag)->first();
        $this->webhook = generatTagDeployWebhook($tag->name);
        $this->applications = $tag->applications()->get();
        $this->services = $tag->services()->get();
        $this->get_deployments();
    }
    public function get_deployments()
    {
        try {
            $resource_ids = $this->applications->pluck('id');
            $this->deployments_per_tag_per_server = ApplicationDeploymentQueue::whereIn("status", ["in_progress", "queued"])->whereIn('application_id', $resource_ids)->get([
                "id",
                "application_id",
                "application_name",
                "deployment_url",
                "pull_request_id",
                "server_name",
                "server_id",
                "status"
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
                $deploy = new Deploy();
                $message->push($deploy->deploy_resource($resource));
            });
            $this->services->each(function ($resource) use ($message) {
                $deploy = new Deploy();
                $message->push($deploy->deploy_resource($resource));
            });
            $this->dispatch('success', 'Mass deployment started.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
    public function mount()
    {
        $this->tags = Tag::ownedByCurrentTeam()->get()->unique('name')->sortBy('name');
        if ($this->tag) {
            $this->updatedTag();
        }
    }
    public function render()
    {
        return view('livewire.tags.index');
    }
}
