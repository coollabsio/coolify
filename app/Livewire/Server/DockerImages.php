<?php

namespace App\Livewire\Server;

use App\Actions\Docker\ListServerDockerImages;
use App\Actions\Docker\RemoveServerDockerImage;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DockerImages extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $parameters = [];

    public array $images = [];

    public string $search = '';

    public string $filter = 'all';

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->parameters = get_route_parameters();
            $this->loadImages();
        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
    }

    #[Computed]
    public function filteredImages(): Collection
    {
        $search = str($this->search)->lower()->trim()->value();

        return collect($this->images)
            ->when($this->filter === 'used', fn (Collection $images) => $images->where('in_use', true))
            ->when($this->filter === 'unused', fn (Collection $images) => $images->where('in_use', false))
            ->when($this->filter === 'dangling', fn (Collection $images) => $images->where('dangling', true))
            ->when($search !== '', function (Collection $images) use ($search) {
                return $images->filter(function (array $image) use ($search) {
                    return collect([
                        data_get($image, 'reference'),
                        data_get($image, 'repository'),
                        data_get($image, 'tag'),
                        data_get($image, 'digest'),
                        data_get($image, 'id'),
                    ])
                        ->filter()
                        ->contains(fn (string $value) => str($value)->lower()->contains($search));
                });
            })
            ->values();
    }

    #[Computed]
    public function imageSummary(): array
    {
        $images = collect($this->images);

        return [
            'total' => $images->count(),
            'used' => $images->where('in_use', true)->count(),
            'unused' => $images->where('in_use', false)->count(),
            'dangling' => $images->where('dangling', true)->count(),
        ];
    }

    public function loadImages()
    {
        try {
            $this->images = ListServerDockerImages::run($this->server)->toArray();
            $this->dispatch('success', 'Docker images refreshed.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function removeImage(string $imageId)
    {
        try {
            $this->authorize('update', $this->server);

            $image = ListServerDockerImages::run($this->server)->firstWhere('id', $imageId);
            if (! $image) {
                throw new \Exception('Docker image not found. Refresh the list and try again.');
            }
            if (data_get($image, 'in_use')) {
                throw new \Exception('This image is used by an existing container and cannot be removed here.');
            }

            RemoveServerDockerImage::run($this->server, $imageId);
            $this->dispatch('success', 'Docker image removed.');
            $this->loadImages();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.docker-images');
    }
}
