<?php

namespace App\Livewire\Tags;

use App\Http\Controllers\Api\DeployController;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Tag;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Tags | Coolify')]
class Show extends Component
{
    use AuthorizesRequests;

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
            $this->tags = Tag::ownedByCurrentTeam()
                ->withCount(['applications', 'services'])
                ->get()
                ->unique('name')
                ->sortBy('name')
                ->values();

            if (str($this->tagName)->isNotEmpty()) {
                $tag = $this->tags->where('name', $this->tagName)->first();
                if (! $tag) {
                    return redirect()->route('tags.show');
                }

                $this->webhook = generateTagDeployWebhook($tag->name);
                $this->applications = $tag->applications()->get();
                $this->services = $tag->services()->get();
                $this->tag = $tag;
                $this->getDeployments();
            } else {
                $this->deploymentsPerTagPerServer = [];
            }
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function getDeployments()
    {
        try {
            if (! $this->applications) {
                $this->deploymentsPerTagPerServer = [];

                return;
            }

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
            $this->applications->each(function ($resource) {
                $this->authorize('deploy', $resource);
            });
            $this->services->each(function ($resource) {
                $this->authorize('deploy', $resource);
            });
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
        return view('livewire.tags.show', [
            'tagsJs' => ($this->tags ?? collect())->map(function (Tag $tag): array {
                $applicationsCount = (int) data_get($tag, 'applications_count', 0);
                $servicesCount = (int) data_get($tag, 'services_count', 0);

                return [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'href' => route('tags.show', ['tagName' => $tag->name]),
                    'applicationsCount' => $applicationsCount,
                    'servicesCount' => $servicesCount,
                    'resourceCount' => $applicationsCount + $servicesCount,
                ];
            })->values()->toArray(),
        ]);
    }
}
