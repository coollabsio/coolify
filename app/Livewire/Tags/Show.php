<?php

namespace App\Livewire\Tags;

use App\Http\Controllers\Api\DeployController;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Tag;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Tags | Coolify')]
class Show extends Component
{
    #[Locked]
    public ?string $tagName = null;

    #[Locked]
    public ?Collection $tags = null;

    #[Locked]
    public ?Tag $tag = null;

    #[Locked]
    public ?Collection $applications = null;

    #[Locked]
    public ?Collection $services = null;

    #[Locked]
    public ?string $webhook = null;

    #[Locked]
    public ?array $deploymentsPerTagPerServer = null;

    public function mount()
    {
        try {
            $this->tags = Tag::ownedByCurrentTeam()->get()->unique('name')->sortBy('name');
            if (str($this->tagName)->isNotEmpty()) {
                $tag = $this->tags->where('name', $this->tagName)->first();
                $this->webhook = generateTagDeployWebhook($tag->name);
                $this->applications = $tag->applications()->get();
                $this->services = $tag->services()->get();
                $this->tag = $tag;
                $this->getDeployments();
            }
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function getDeployments()
    {
        try {
            $resource_ids = $this->applications->pluck('id');
            $this->deploymentsPerTagPerServer = ApplicationDeploymentQueue::whereIn('status', ['in_progress', 'queued'])->whereIn('application_id', $resource_ids)->get([
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

    public function redeployAll()
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
