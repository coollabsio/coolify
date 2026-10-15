<?php

namespace App\Livewire\Tags;

use App\Http\Controllers\Api\DeployController;
use App\Livewire\Concerns\CreatesTags;
use App\Livewire\Concerns\RemembersCanvasView;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Tag;
use App\Services\TagCanvas;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Tags | Coolify')]
class Show extends Component
{
    use AuthorizesRequests;
    use CreatesTags;
    use RemembersCanvasView;

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

    /** The "Connect to resources" modal on small screens, where the tags canvas is not shown. */
    public bool $connectResourcesOpen = false;

    public function mount()
    {
        if (str($this->tagName)->isEmpty() && $this->prefersCanvasView('tags')) {
            return $this->redirectRoute('tags.canvas', navigate: true);
        }

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

    public function openConnectResources(): void
    {
        $this->connectResourcesOpen = true;
    }

    /**
     * Adds the tag to a resource of the tag's team, or removes it when the resource already has it.
     */
    public function toggleResource(string $uuid): void
    {
        try {
            $resource = app(TagCanvas::class)->resources($this->tag->team_id)->get($uuid);
            if (! $resource) {
                $this->dispatch('error', 'Resource not found.');

                return;
            }
            $this->authorize('update', $resource);

            if ($resource->tags->contains('id', $this->tag->id)) {
                $resource->tags()->detach($this->tag->id);
            } else {
                $resource->tags()->attach($this->tag->id);
            }

            $this->applications = $this->tag->applications()->get();
            $this->services = $this->tag->services()->get();
            $this->getDeployments();
        } catch (\Exception $e) {
            handleError($e, $this);
        }
    }

    /**
     * Deletes the tag and removes it from every resource.
     */
    public function deleteTag()
    {
        try {
            $this->authorize('delete', $this->tag);
            // taggables rows cascade-delete via FK on tag_id
            $this->tag->delete();

            return $this->redirectRoute('tags.show', navigate: true);
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
            'connectableResources' => $this->connectableResources(),
        ]);
    }

    /**
     * Resources of the tag's team for the "Connect to resources" modal, by "Project / Environment".
     * Loaded only while the modal is open.
     *
     * @return Collection<string, Collection<int, array{uuid: string, name: string, tagged: bool, can_update: bool}>>
     */
    private function connectableResources(): Collection
    {
        if (! $this->connectResourcesOpen || ! $this->tag) {
            return collect();
        }

        return app(TagCanvas::class)->resources($this->tag->team_id)
            ->groupBy(fn ($resource) => $resource->environment->project->name.' / '.$resource->environment->name)
            ->sortKeys(SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn (Collection $resources) => $resources->map(fn ($resource) => [
                'uuid' => $resource->uuid,
                'name' => $resource->name,
                'tagged' => $resource->tags->contains('id', $this->tag->id),
                'can_update' => auth()->user()->can('update', $resource),
            ])->values());
    }
}
