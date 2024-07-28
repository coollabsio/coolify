<?php

namespace App\Livewire\Tags;

use App\Http\Controllers\Api\DeployController;
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

    protected $listeners = ['deployments' => 'update_deployments'];

    public function update_deployments($deployments)
    {
        $this->deployments_per_tag_per_server = $deployments;
    }

    public function tag_updated()
    {
        if ($this->tag == '') {
            return;
        }
        $tag = $this->tags->where('name', $this->tag)->first();
        if (! $tag) {
            $this->dispatch('error', "Tag ({$this->tag}) not found.");
            $this->tag = '';

            return;
        }
        $this->webhook = generatTagDeployWebhook($tag->name);
        $this->applications = $tag->applications()->get();
        $this->services = $tag->services()->get();
    }

    public function redeploy_all()
    {
        try {
            $this->applications->each(function ($resource) {
                $deploy = new DeployController;
                $deploy->deploy_resource($resource);
            });
            $this->services->each(function ($resource) {
                $deploy = new DeployController;
                $deploy->deploy_resource($resource);
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
            $this->tag_updated();
        }
    }

    public function render()
    {
        return view('livewire.tags.index');
    }
}
