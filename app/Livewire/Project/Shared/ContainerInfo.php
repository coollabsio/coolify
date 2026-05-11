<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Support\ContainerInfoFormatter;
use App\Support\ValidationPatterns;
use Illuminate\Support\Collection;
use Livewire\Component;

class ContainerInfo extends Component
{
    public $resource;

    public Collection $containers;

    public ?string $selectedContainerKey = null;

    public array $containerInfo = [];

    public ?string $error = null;

    public function mount($resource): void
    {
        $this->resource = $resource;
        $this->containers = collect();
        $this->loadContainers();

        if ($this->containers->count() === 1) {
            $this->selectedContainerKey = data_get($this->containers->first(), 'key');
            $this->loadContainerInfo();
        }
    }

    public function updatedSelectedContainerKey(): void
    {
        $this->loadContainerInfo();
    }

    public function refreshInfo(): void
    {
        $this->loadContainers();
        $this->loadContainerInfo();
    }

    public function loadContainerInfo(): void
    {
        $this->containerInfo = [];
        $this->error = null;

        if (blank($this->selectedContainerKey)) {
            return;
        }

        $payload = $this->selectedContainerPayload();
        if (is_null($payload)) {
            $this->error = 'Selected container is not available anymore.';

            return;
        }

        $server = data_get($payload, 'server');
        $containerName = (string) data_get($payload, 'container.Names');

        if (! $server instanceof Server || ! ValidationPatterns::isValidContainerName($containerName)) {
            $this->error = 'Selected container has invalid metadata.';

            return;
        }

        $rawInspect = instant_remote_process([
            "docker inspect ".escapeshellarg($containerName)." --format '{{json .}}'",
        ], $server, false);

        if (blank($rawInspect)) {
            $this->error = 'Container details are unavailable. The container may have been removed or the server is unreachable.';

            return;
        }

        $inspect = format_docker_command_output_to_json($rawInspect)->first();
        if (! is_array($inspect)) {
            $this->error = 'Container details are unavailable. The container may have been removed or the server is unreachable.';

            return;
        }

        $this->containerInfo = ContainerInfoFormatter::fromDockerInspect($inspect);
    }

    public function render()
    {
        return view('livewire.project.shared.container-info');
    }

    private function loadContainers(): void
    {
        $containers = collect();

        foreach ($this->servers() as $server) {
            if (! $server->isFunctional() || $server->isSwarm()) {
                continue;
            }

            $containers = $containers->merge($this->containersForServer($server));
        }

        $this->containers = $containers
            ->filter(fn (array $payload) => filled(data_get($payload, 'container.Names')))
            ->sortBy(fn (array $payload) => data_get($payload, 'container.Names'))
            ->values();

        if ($this->selectedContainerKey && $this->selectedContainerPayload() === null) {
            $this->selectedContainerKey = null;
        }

        if (blank($this->selectedContainerKey) && $this->containers->count() === 1) {
            $this->selectedContainerKey = data_get($this->containers->first(), 'key');
        }
    }

    private function containersForServer(Server $server): Collection
    {
        if ($this->resource instanceof Application) {
            return getCurrentApplicationContainerStatus($server, $this->resource->id, includePullrequests: true)
                ->map(fn (array $container) => $this->containerPayload($server, $container));
        }

        if ($this->resource instanceof Service) {
            return getCurrentServiceContainerStatus($server, $this->resource->id)
                ->map(fn (array $container) => $this->containerPayload($server, $container));
        }

        return collect([
            $this->containerPayload($server, [
                'Names' => data_get($this->resource, 'uuid'),
                'Image' => data_get($this->resource, 'image'),
                'State' => data_get($this->resource, 'status'),
            ]),
        ]);
    }

    private function containerPayload(Server $server, array $container): array
    {
        $containerName = (string) data_get($container, 'Names');

        return [
            'key' => "{$server->uuid}|{$containerName}",
            'server' => $server,
            'container' => $container,
        ];
    }

    private function servers(): Collection
    {
        if ($this->resource instanceof Application) {
            return collect([data_get($this->resource, 'destination.server')])
                ->merge($this->resource->additional_servers);
        }

        if ($this->resource instanceof Service) {
            return collect([data_get($this->resource, 'server')]);
        }

        return collect([data_get($this->resource, 'destination.server')]);
    }

    private function selectedContainerPayload(): ?array
    {
        return $this->containers
            ->first(fn (array $payload) => data_get($payload, 'key') === $this->selectedContainerKey);
    }
}
