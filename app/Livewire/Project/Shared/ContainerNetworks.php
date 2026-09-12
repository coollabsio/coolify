<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class ContainerNetworks extends Component
{
    use AuthorizesRequests;

    public $resource;

    public Collection $connectedNetworks;

    public Collection $availableNetworks;

    public string $selectedNetwork = '';

    public ?Server $server = null;

    public ?string $containerName = null;

    public bool $isSwarm = false;

    public bool $isSupported = false;

    public function mount()
    {
        $this->connectedNetworks = collect();
        $this->availableNetworks = collect();
        $this->refreshNetworks();
    }

    public function refreshNetworks()
    {
        try {
            $this->authorize('view', $this->resource);
            $this->connectedNetworks = collect();
            $this->availableNetworks = collect();
            $this->isSupported = false;
            $this->resolveContainerContext();
            if (! $this->server || ! $this->containerName) {
                return;
            }
            $this->isSwarm = $this->server->isSwarm();
            if ($this->isSwarm || ! $this->server->isFunctional()) {
                return;
            }
            $this->isSupported = true;
            $this->connectedNetworks = $this->getConnectedNetworks();
            $this->availableNetworks = $this->getAvailableNetworks();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function connectNetwork()
    {
        try {
            $this->authorize('update', $this->resource);
            if (! $this->isSupported) {
                $this->dispatch('error', 'Container networks cannot be managed right now.');

                return;
            }
            if (str($this->selectedNetwork)->isEmpty()) {
                $this->dispatch('error', 'Please select a network to connect.');

                return;
            }
            if ($this->connectedNetworks->contains($this->selectedNetwork)) {
                $this->dispatch('error', 'The container is already connected to this network.');

                return;
            }
            $safeNetwork = escapeshellarg($this->selectedNetwork);
            $safeContainer = escapeshellarg($this->containerName);
            instant_remote_process(["docker network connect {$safeNetwork} {$safeContainer}"], $this->server);
            $this->selectedNetwork = '';
            $this->refreshNetworks();
            $this->dispatch('success', 'Container connected to the network.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function disconnectNetwork(string $network)
    {
        try {
            $this->authorize('update', $this->resource);
            if (! $this->isSupported) {
                $this->dispatch('error', 'Container networks cannot be managed right now.');

                return;
            }
            if (! $this->connectedNetworks->contains($network)) {
                return;
            }
            $safeNetwork = escapeshellarg($network);
            $safeContainer = escapeshellarg($this->containerName);
            instant_remote_process(["docker network disconnect {$safeNetwork} {$safeContainer}"], $this->server);
            $this->refreshNetworks();
            $this->dispatch('success', 'Container disconnected from the network.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function resolveContainerContext(): void
    {
        $this->server = null;
        $this->containerName = null;
        $this->isSwarm = false;
        if (! $this->resource) {
            return;
        }
        if ($this->resource instanceof Application) {
            if (data_get($this->resource, 'build_pack') === 'dockercompose') {
                return;
            }
            $this->server = $this->resource->destination->server;
            $this->containerName = $this->resolveApplicationContainerName($this->server, $this->resource->id) ?? $this->resource->uuid;
        } elseif ($this->resource instanceof ServiceApplication || $this->resource instanceof ServiceDatabase) {
            $this->server = $this->resource->service->destination->server;
            $this->containerName = "{$this->resource->name}-{$this->resource->service->uuid}";
        } elseif (str($this->resource->getMorphClass())->startsWith('App\Models\Standalone')) {
            $this->server = $this->resource->destination->server;
            $this->containerName = $this->resource->uuid;
        }
    }

    private function resolveApplicationContainerName(Server $server, int $applicationId): ?string
    {
        try {
            $containers = getCurrentApplicationContainerStatus($server, $applicationId);
        } catch (\Throwable $e) {
            return null;
        }

        return data_get($containers->first(), 'Names');
    }

    private function getConnectedNetworks(): Collection
    {
        $container = getContainerStatus($this->server, $this->containerName, all_data: true, throwError: false);
        if (! is_array($container)) {
            return collect();
        }
        $networks = data_get($container, 'NetworkSettings.Networks', []);

        return collect($networks)->keys()->values();
    }

    private function getAvailableNetworks(): Collection
    {
        $networks = instant_remote_process(['docker network ls --format "{{json .}}"'], $this->server, false);
        if (! $networks) {
            return collect();
        }

        return format_docker_command_output_to_json($networks)
            ->filter(function ($network) {
                $name = data_get($network, 'Name');

                return $name !== 'bridge' && $name !== 'host' && $name !== 'none';
            })
            ->reject(fn ($network) => $this->connectedNetworks->contains(data_get($network, 'Name')))
            ->map(fn ($network) => [
                'name' => data_get($network, 'Name'),
                'driver' => data_get($network, 'Driver'),
            ])
            ->values();
    }

    public function render()
    {
        return view('livewire.project.shared.container-networks');
    }
}
