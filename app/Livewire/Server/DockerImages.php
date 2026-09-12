<?php

namespace App\Livewire\Server;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class DockerImages extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $parameters = [];

    public array $images = [];

    public string $search = '';

    public bool $loaded = false;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->parameters = get_route_parameters();
        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
    }

    public function load(): void
    {
        try {
            if ($this->server->isFunctional()) {
                $containersByImage = $this->containersByImage();
                $this->images = format_docker_command_output_to_json(
                    instant_remote_process(["docker image ls --no-trunc --format '{{json .}}'"], $this->server, false)
                )
                    ->map(fn (array $image) => $this->summarize($image, $containersByImage))
                    ->values()
                    ->all();
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        } finally {
            $this->loaded = true;
        }
    }

    public function delete(string $reference)
    {
        try {
            $this->authorize('update', $this->server);
            $image = collect($this->images)->firstWhere('reference', $reference);
            if ($image === null) {
                throw new \RuntimeException('This image is no longer listed. Refresh and try again.');
            }
            if ($image['usedBy'] !== []) {
                throw new \RuntimeException('Image is in use by: '.implode(', ', $image['usedBy']));
            }
            instant_remote_process(['docker rmi '.escapeshellarg($reference)], $this->server);
            auditLog('ui.server.docker_image_deleted', [
                'team_id' => $this->server->team_id,
                'server_uuid' => $this->server->uuid,
                'server_name' => $this->server->name,
                'image' => $reference,
            ]);
            $this->dispatch('success', "Image {$reference} deleted.");
            $this->load();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function deleteUnused()
    {
        try {
            $this->authorize('update', $this->server);
            $unused = collect($this->images)
                ->filter(fn (array $image) => $image['usedBy'] === [])
                ->pluck('reference')
                ->values();
            if ($unused->isEmpty()) {
                throw new \RuntimeException('There are no unused images to delete.');
            }
            $references = $unused->map(fn (string $reference) => escapeshellarg($reference))->implode(' ');
            instant_remote_process(["docker rmi {$references}"], $this->server);
            auditLog('ui.server.docker_unused_images_deleted', [
                'team_id' => $this->server->team_id,
                'server_uuid' => $this->server->uuid,
                'server_name' => $this->server->name,
                'images' => $unused->all(),
            ]);
            $this->dispatch('success', $unused->count().' unused image(s) deleted.');
            $this->load();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function unusedCount(): int
    {
        return collect($this->images)->filter(fn (array $image) => $image['usedBy'] === [])->count();
    }

    private function containersByImage(): array
    {
        return format_docker_command_output_to_json(
            instant_remote_process(["docker ps -a --format '{{json .}}'"], $this->server, false)
        )
            ->groupBy(fn (array $container) => $this->normalizeReference((string) data_get($container, 'Image')))
            ->map(fn ($containers) => $containers
                ->map(fn (array $container) => ltrim((string) data_get($container, 'Names'), '/'))
                ->sort()
                ->values()
                ->all())
            ->all();
    }

    // `docker ps` shows whatever reference the container was created with, so
    // "nginx" and "nginx:latest" must map to the same image list entry.
    private function normalizeReference(string $reference): string
    {
        if ($reference === '') {
            return $reference;
        }
        $slash = strrpos($reference, '/');
        $colon = strrpos($reference, ':');
        if ($colon !== false && ($slash === false || $colon > $slash)) {
            return $reference;
        }

        return $reference.':latest';
    }

    private function summarize(array $image, array $containersByImage): array
    {
        $repository = (string) data_get($image, 'Repository');
        $tag = (string) data_get($image, 'Tag');
        $id = (string) data_get($image, 'ID');
        $dangling = $repository === '<none>' || $tag === '<none>';
        $reference = $dangling ? $id : $this->normalizeReference("{$repository}:{$tag}");
        $usedBy = $containersByImage[$reference] ?? $containersByImage[$id] ?? [];

        return [
            'id' => $id,
            'shortId' => str($id)->after('sha256:')->limit(12, '')->value(),
            'repository' => $repository,
            'tag' => $tag,
            'dangling' => $dangling,
            'reference' => $reference,
            'size' => data_get($image, 'Size'),
            'created' => data_get($image, 'CreatedSince'),
            'usedBy' => $usedBy,
        ];
    }

    public function render()
    {
        $search = trim($this->search);
        $visibleImages = collect($this->images);
        if ($search !== '') {
            $visibleImages = $visibleImages->filter(fn (array $image) => str($image['repository'].' '.$image['tag'].' '.$image['shortId'])
                ->contains($search, ignoreCase: true));
        }

        return view('livewire.server.docker-images', [
            'visibleImages' => $visibleImages->values(),
        ]);
    }
}
