<?php

namespace App\Livewire\Destination;

use App\Exceptions\DockerNetworkCreationException;
use App\Exceptions\DockerNetworkValidationException;
use App\Exceptions\ProxyNetworkReconciliationException;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Services\Docker\DestinationNetworkSynchronizer;
use App\Services\Docker\DockerNetworkManager;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Index extends Component
{
    use AuthorizesRequests;

    public Collection $servers;

    #[Url(as: 'server', keep: true, history: true)]
    public ?string $selectedServerUuid = null;

    public string $createDisplayName = '';

    public string $createDockerNetworkName = '';

    public string $createServerUuid = '';

    public ?string $createSubnet = null;

    public ?string $createGateway = null;

    public bool $createInternal = false;

    public bool $createProxyAccess = false;

    public int $inventoryVersion = 0;

    protected $listeners = [
        'destinationCatalogUpdated' => 'reloadServers',
    ];

    public function mount(): void
    {
        $this->servers = $this->servers();

        if ($this->servers->isEmpty()) {
            $this->selectedServerUuid = null;

            return;
        }

        if (! $this->selectedServer()) {
            $this->selectedServerUuid = $this->servers->first()->uuid;
        }

        $this->resetCreateForm();
    }

    public function updatedSelectedServerUuid(): void
    {
        if (! $this->selectedServer()) {
            $this->selectedServerUuid = $this->servers->first()?->uuid;
        }

        $this->createServerUuid = $this->selectedServerUuid ?? '';
    }

    public function reloadServers(): void
    {
        $this->servers = $this->servers();
    }

    public function updatedCreateInternal(bool $internal): void
    {
        if ($internal) {
            $this->createProxyAccess = false;
        }
    }

    public function createDestination(): void
    {
        $this->validate([
            'createDisplayName' => ['required', 'string', 'max:255'],
            'createDockerNetworkName' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
            'createServerUuid' => ['required', 'string'],
            'createSubnet' => ['nullable', 'string', 'max:255'],
            'createGateway' => ['nullable', 'string', 'max:255'],
            'createInternal' => ['boolean'],
            'createProxyAccess' => ['boolean'],
        ]);

        $server = Server::ownedByCurrentTeam()
            ->whereUuid($this->createServerUuid)
            ->first();

        if (! $server) {
            $this->addError('createServerUuid', 'Select an authorized server.');

            return;
        }

        $this->authorize('update', $server);
        $this->authorize('create', StandaloneDocker::class);

        $network = null;
        $destination = null;

        try {
            $network = app(DockerNetworkManager::class)->create($server, [
                'display_name' => $this->createDisplayName,
                'docker_network_name' => $this->createDockerNetworkName,
                'driver' => 'bridge',
                'subnet' => blank($this->createSubnet) ? null : $this->createSubnet,
                'gateway' => blank($this->createGateway) ? null : $this->createGateway,
                'internal' => $this->createInternal,
                'proxy_access' => $this->createInternal ? false : $this->createProxyAccess,
            ]);

            $destination = StandaloneDocker::withoutEvents(function () use ($server, $network): StandaloneDocker {
                $destination = new StandaloneDocker;
                $destination->forceFill([
                    'uuid' => (string) new Cuid2,
                    'name' => trim($this->createDisplayName),
                    'network' => $network->docker_network_name,
                    'server_id' => $server->id,
                ])->save();

                return $destination;
            });

            $network->update(['available_during_creation' => true]);
            app(DestinationNetworkSynchronizer::class)->sync($server);

            $createdNetworkName = $network->docker_network_name;
            $this->reloadServers();
            $this->inventoryVersion++;
            $this->resetCreateForm();
            $this->dispatch('destination-created');
            $this->dispatch('success', "Destination created. Docker network: {$createdNetworkName}");
        } catch (DockerNetworkValidationException $e) {
            $this->addError('createDockerNetworkName', $e->getMessage());
        } catch (DockerNetworkCreationException|ProxyNetworkReconciliationException $e) {
            $this->dispatch('error', $e->getMessage());
        } catch (\Throwable $e) {
            $destination?->delete();

            if ($network) {
                try {
                    app(DockerNetworkManager::class)->delete($network);
                } catch (\Throwable $cleanupError) {
                    report($cleanupError);
                }
            }

            report($e);
            $this->dispatch('error', 'Destination could not be saved. The created network was rolled back.');
        }
    }

    public function resetCreateForm(): void
    {
        $this->resetValidation();
        $this->createDisplayName = '';
        $this->createDockerNetworkName = 'coolify-net-'.substr((string) new Cuid2, 0, 12);
        $this->createServerUuid = $this->selectedServerUuid ?? $this->servers->first()?->uuid ?? '';
        $this->createSubnet = null;
        $this->createGateway = null;
        $this->createInternal = false;
        $this->createProxyAccess = false;
    }

    public function selectedServer(): ?Server
    {
        if (! $this->selectedServerUuid) {
            return null;
        }

        return $this->servers->firstWhere('uuid', $this->selectedServerUuid);
    }

    public function serverOptions(): array
    {
        return $this->servers
            ->map(fn (Server $server): array => [
                'uuid' => $server->uuid,
                'name' => $server->name,
            ])
            ->values()
            ->all();
    }

    public function destinations(): Collection
    {
        return $this->servers
            ->flatMap(function (Server $server): Collection {
                return $server->standaloneDockers
                    ->map(fn (StandaloneDocker $destination): array => $this->destinationSummary($destination, $server))
                    ->concat(
                        $server->swarmDockers->map(fn (SwarmDocker $destination): array => $this->destinationSummary($destination, $server)),
                    );
            })
            ->sortBy(fn (array $destination): string => mb_strtolower($destination['server_name'].'|'.$destination['name'].'|'.$destination['uuid']))
            ->values();
    }

    public function render()
    {
        return view('livewire.destination.index', [
            'destinations' => $this->destinations(),
            'selectedServer' => $this->selectedServer(),
            'serverOptions' => $this->serverOptions(),
        ]);
    }

    /**
     * @return array{uuid: string, name: string, network: string, server_name: string, type: string, deprecated: bool}
     */
    private function destinationSummary(StandaloneDocker|SwarmDocker $destination, Server $server): array
    {
        return [
            'uuid' => $destination->uuid,
            'name' => $destination->name,
            'network' => $destination->network,
            'server_name' => $server->name,
            'type' => $destination instanceof SwarmDocker ? 'Swarm' : 'Standalone',
            'deprecated' => $destination instanceof SwarmDocker,
        ];
    }

    private function servers(): Collection
    {
        return Server::ownedByCurrentTeam()
            ->with(['settings', 'standaloneDockers', 'swarmDockers'])
            ->orderBy('name')
            ->get();
    }
}
