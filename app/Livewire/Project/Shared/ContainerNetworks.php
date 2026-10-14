<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ContainerNetworks extends Component
{
    use AuthorizesRequests;

    private const RESOURCE_TYPES = [
        Application::class,
        ServiceApplication::class,
        ServiceDatabase::class,
        StandaloneClickhouse::class,
        StandaloneDragonfly::class,
        StandaloneKeydb::class,
        StandaloneMariadb::class,
        StandaloneMongodb::class,
        StandaloneMysql::class,
        StandalonePostgresql::class,
        StandaloneRedis::class,
    ];

    #[Locked]
    public string $resourceType = '';

    #[Locked]
    public string $resourceId = '';

    public $resource;

    public Collection $connectedNetworks;

    public Collection $availableNetworks;

    public Collection $allowedNetworks;

    public string $selectedNetwork = '';

    public ?Server $server = null;

    public ?string $containerName = null;

    public bool $isSwarm = false;

    public bool $isSupported = false;

    public function mount()
    {
        if (! $this->resource instanceof Model) {
            throw new AuthorizationException('Resource type is not supported.');
        }

        $this->resourceType = $this->resource::class;
        $this->resourceId = (string) $this->resource->getKey();
        $this->connectedNetworks = collect();
        $this->availableNetworks = collect();
        $this->allowedNetworks = collect();
        $this->refreshNetworks();
    }

    public function refreshNetworks()
    {
        $resource = $this->resolveAuthorizedResource('view');
        $this->resetNetworkState();

        try {
            $server = $this->resolveServer($resource);
            $this->server = $server;
            if (! $server) {
                return;
            }

            $this->isSwarm = $server->isSwarm();
            if ($this->isSwarm || ! $server->isFunctional()) {
                return;
            }

            $containerId = $this->resolveContainerId($resource, $server);
            if (! $containerId) {
                return;
            }

            $this->containerName = $containerId;
            $this->isSupported = true;
            $this->allowedNetworks = $this->getAllowedNetworks($server);
            $this->connectedNetworks = $this->getConnectedNetworks($server, $containerId);
            $this->availableNetworks = $this->getAvailableNetworks(
                $server,
                $this->allowedNetworks,
                $this->connectedNetworks,
            );
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function connectNetwork()
    {
        $resource = $this->resolveAuthorizedResource('update');

        try {
            $server = $this->resolveServer($resource);
            $containerId = $server ? $this->resolveContainerId($resource, $server) : null;
            if (! $this->isManageable($server, $containerId)) {
                $this->dispatch('error', 'Container networks cannot be managed right now.');

                return;
            }

            $selectedNetwork = trim($this->selectedNetwork);
            if ($selectedNetwork === '') {
                $this->dispatch('error', 'Please select a network to connect.');

                return;
            }

            $connectedNetworks = $this->getConnectedNetworks($server, $containerId);
            $allowedNetworks = $this->getAllowedNetworks($server);
            if (! $allowedNetworks->contains($selectedNetwork)) {
                $this->dispatch('error', 'The selected network is not available for this resource.');

                return;
            }
            if ($connectedNetworks->contains($selectedNetwork)) {
                $this->dispatch('error', 'The container is already connected to this network.');

                return;
            }

            $safeNetwork = escapeshellarg($selectedNetwork);
            $safeContainer = escapeshellarg($containerId);
            instant_remote_process(["docker network connect {$safeNetwork} {$safeContainer}"], $server);
            $this->selectedNetwork = '';
            $this->refreshNetworks();
            $this->dispatch('success', 'Container connected to the network.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function disconnectNetwork(string $network)
    {
        $resource = $this->resolveAuthorizedResource('update');

        try {
            $server = $this->resolveServer($resource);
            $containerId = $server ? $this->resolveContainerId($resource, $server) : null;
            if (! $this->isManageable($server, $containerId)) {
                $this->dispatch('error', 'Container networks cannot be managed right now.');

                return;
            }

            $connectedNetworks = $this->getConnectedNetworks($server, $containerId);
            $allowedNetworks = $this->getAllowedNetworks($server);
            if (! $allowedNetworks->contains($network)) {
                $this->dispatch('error', 'The selected network is not available for this resource.');

                return;
            }
            if (! $connectedNetworks->contains($network)) {
                return;
            }

            $safeNetwork = escapeshellarg($network);
            $safeContainer = escapeshellarg($containerId);
            instant_remote_process(["docker network disconnect {$safeNetwork} {$safeContainer}"], $server);
            $this->refreshNetworks();
            $this->dispatch('success', 'Container disconnected from the network.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function resetNetworkState(): void
    {
        $this->server = null;
        $this->containerName = null;
        $this->connectedNetworks = collect();
        $this->availableNetworks = collect();
        $this->allowedNetworks = collect();
        $this->isSwarm = false;
        $this->isSupported = false;
    }

    private function resolveAuthorizedResource(string $ability): Model
    {
        if (! in_array($this->resourceType, self::RESOURCE_TYPES, true)) {
            throw new AuthorizationException('Resource type is not supported.');
        }

        $resourceClass = $this->resourceType;
        $resource = $resourceClass::ownedByCurrentTeam()
            ->whereKey($this->resourceId)
            ->first();
        if (! $resource) {
            throw new AuthorizationException('Resource is unavailable.');
        }

        $this->authorize($ability, $resource);

        return $resource;
    }

    private function resolveServer(Model $resource): ?Server
    {
        $destination = match (true) {
            $resource instanceof ServiceApplication, $resource instanceof ServiceDatabase => $resource->service?->destination,
            default => $resource->destination,
        };
        $serverId = data_get($destination, 'server_id');
        if (! $serverId) {
            return null;
        }

        $server = Server::ownedByCurrentTeam()->find($serverId);
        if (! $server) {
            throw new AuthorizationException('Server is unavailable.');
        }

        return $server;
    }

    private function resolveContainerId(Model $resource, Server $server): ?string
    {
        $containers = match (true) {
            $resource instanceof Application => getCurrentApplicationContainerStatus($server, $resource->id, 0),
            $resource instanceof ServiceApplication, $resource instanceof ServiceDatabase => getCurrentServiceSubContainerStatus($server, $resource->service_id, $resource->name),
            default => getCurrentDatabaseContainerStatus($server, $resource->id),
        };

        if ($containers->count() !== 1) {
            return null;
        }

        $containerId = data_get($containers->first(), 'ID');
        if (! is_string($containerId) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/D', $containerId) !== 1) {
            return null;
        }

        return $containerId;
    }

    private function isManageable(?Server $server, ?string $containerId): bool
    {
        return $server !== null
            && $containerId !== null
            && ! $server->isSwarm()
            && $server->isFunctional();
    }

    private function getConnectedNetworks(Server $server, string $containerId): Collection
    {
        $container = getContainerStatus($server, $containerId, all_data: true, throwError: true);
        if (! is_array($container)) {
            return collect();
        }

        $networks = data_get($container, 'NetworkSettings.Networks', []);

        return collect(is_array($networks) ? array_keys($networks) : [])
            ->filter(fn (string $network): bool => $network !== '')
            ->values();
    }

    private function getAllowedNetworks(Server $server): Collection
    {
        return StandaloneDocker::ownedByCurrentTeam()
            ->where('server_id', $server->id)
            ->pluck('network')
            ->filter(fn (?string $network): bool => filled($network))
            ->unique()
            ->values();
    }

    private function getAvailableNetworks(Server $server, Collection $allowedNetworks, Collection $connectedNetworks): Collection
    {
        $networks = instant_remote_process(['docker network ls --format "{{json .}}"'], $server, false);
        if (! $networks) {
            return collect();
        }

        return format_docker_command_output_to_json($networks)
            ->filter(fn (array $network): bool => $allowedNetworks->contains(data_get($network, 'Name')))
            ->reject(fn (array $network): bool => $connectedNetworks->contains(data_get($network, 'Name')))
            ->map(fn (array $network): array => [
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
