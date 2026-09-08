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

    public ?array $usage = null;

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
            if (! $this->server->isFunctional()) {
                return;
            }
            $containersByImage = $this->containersByImage();
            $this->images = format_docker_command_output_to_json(
                instant_remote_process(["docker image ls --no-trunc --format '{{json .}}'"], $this->server, false)
            )
                ->map(fn (array $image) => $this->summarize($image, $containersByImage))
                ->values()
                ->all();
            $this->usage = $this->diskUsage();
        } catch (\Throwable $e) {
            handleError($e, $this);
        } finally {
            $this->loaded = true;
        }
    }

    public function delete(string $reference): void
    {
        try {
            $this->authorize('update', $this->server);
            $image = collect($this->images)->firstWhere('reference', $reference);
            if (! $image) {
                throw new \RuntimeException('Unknown image, refresh the page and try again.');
            }
            if ($image['containers'] !== []) {
                throw new \RuntimeException('This image is used by '.implode(', ', $image['containers']).'. Remove those containers first.');
            }
            instant_remote_process(['docker image rm '.escapeshellarg($reference)], $this->server);
            auditLog('ui.server.docker_image_deleted', [
                'team_id' => $this->server->team_id,
                'server_uuid' => $this->server->uuid,
                'server_name' => $this->server->name,
                'image' => $reference,
            ]);
            $this->dispatch('success', "Deleted {$reference}.");
            $this->load();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Map every image ID to the containers using it. Containers are inspected instead of
     * read from `docker ps --format {{.Image}}`, because that column shows whatever
     * reference the container was started with, which may be a tag that has since moved.
     *
     * @return array<string, array<int, string>>
     */
    private function containersByImage(): array
    {
        $output = instant_remote_process(
            ["docker ps -a --no-trunc --format '{{.ID}}' | xargs -r docker container inspect --format '{{.Image}}#{{.Name}}' 2>/dev/null || true"],
            $this->server,
            false
        );

        return str($output ?? '')->explode("\n")
            ->map(fn ($line) => explode('#', trim($line), 2))
            ->filter(fn ($parts) => count($parts) === 2 && $parts[0] !== '')
            ->groupBy(fn ($parts) => $parts[0])
            ->map(fn ($rows) => $rows->map(fn ($parts) => ltrim($parts[1], '/'))->sort()->values()->all())
            ->all();
    }

    /**
     * Total and reclaimable image size as reported by Docker itself, so shared layers
     * are not counted twice the way summing the per-image sizes would.
     */
    private function diskUsage(): ?array
    {
        $row = format_docker_command_output_to_json(
            instant_remote_process(["docker system df --format '{{json .}}'"], $this->server, false)
        )->firstWhere('Type', 'Images');

        if (! $row) {
            return null;
        }

        return [
            'size' => data_get($row, 'Size'),
            'reclaimable' => data_get($row, 'Reclaimable'),
        ];
    }

    private function summarize(array $image, array $containersByImage): array
    {
        $repository = (string) data_get($image, 'Repository');
        $tag = (string) data_get($image, 'Tag');
        $id = (string) data_get($image, 'ID');
        $isDangling = $repository === '<none>' || $tag === '<none>';

        return [
            'id' => $id,
            'short_id' => str($id)->after('sha256:')->limit(12, '')->value(),
            'repository' => $repository,
            'tag' => $tag,
            'dangling' => $isDangling,
            // Remove the tag when there is one: an image ID with several tags cannot be
            // removed by ID, Docker refuses with "image is referenced in multiple repositories".
            'reference' => $isDangling ? $id : "{$repository}:{$tag}",
            'size' => data_get($image, 'Size'),
            'created' => data_get($image, 'CreatedSince'),
            'containers' => $containersByImage[$id] ?? [],
        ];
    }

    public function render()
    {
        $search = trim($this->search);
        $images = collect($this->images);
        if ($search !== '') {
            $images = $images->filter(fn (array $image) => str($image['repository'].':'.$image['tag'].' '.$image['short_id'])
                ->contains($search, ignoreCase: true));
        }

        return view('livewire.server.docker-images', [
            // Not "images": Livewire shares public properties with the view, so that name is
            // already taken by the unfiltered array.
            'visibleImages' => $images->values(),
        ]);
    }
}
