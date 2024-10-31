<?php

namespace App\Livewire\Tags;

use App\Http\Controllers\Api\DeployController;
use App\Models\Tag;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Tags | Coolify')]
class Index extends Component
{
    #[Url()]
    public ?string $tag = null;

    public Collection $tags;

    public Collection $applications;

    public Collection $services;

    public $webhook = null;

    public $deploymentsPerTagPerServer = [];

    protected $listeners = ['deployments' => 'updateDeployments'];

    public function render()
    {
        return view('livewire.tags.index');
    }

    public function mount()
    {
        $this->tags = Tag::ownedByCurrentTeam()->get()->unique('name')->sortBy('name');
        if ($this->tag) {
            $this->tagUpdated();
        }
    }

    public function updateDeployments($deployments)
    {
        $this->deploymentsPerTagPerServer = $deployments;
    }

    public function tagUpdated()
    {
        if ($this->tag == '') {
            return;
        }
        $sanitizedTag = htmlspecialchars($this->tag, ENT_QUOTES, 'UTF-8');
        $tag = $this->tags->where('name', $sanitizedTag)->first();
        if (! $tag) {
            $this->dispatch('error', 'Tag ('.e($sanitizedTag).') not found.');
            $this->tag = '';

            return;
        }
        $this->webhook = generateTagDeployWebhook($tag->name);
        $this->applications = $tag->applications()->get();
        $this->services = $tag->services()->get();
    }

    public function redeployAll()
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
}
