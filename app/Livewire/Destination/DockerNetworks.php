<?php

namespace App\Livewire\Destination;

use App\Exceptions\DockerNetworkCreationException;
use App\Exceptions\DockerNetworkDeletionException;
use App\Exceptions\DockerNetworkValidationException;
use App\Exceptions\ProxyNetworkReconciliationException;
use App\Models\DockerNetwork;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Services\Docker\DestinationNameResolver;
use App\Services\Docker\DestinationNetworkSynchronizer;
use App\Services\Docker\DockerNetworkCatalogRefresher;
use App\Services\Docker\DockerNetworkClassifier;
use App\Services\Docker\DockerNetworkManager;
use App\Services\Docker\ProxyNetworkReconciler;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class DockerNetworks extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $serverOptions = [];

    public string $selectedInventoryServerUuid = '';

    public string $filter = 'all';

    public string $search = '';

    public bool $showCreateForm = false;

    public ?int $selectedNetworkId = null;

    public bool $showInspectModal = false;

    public ?int $editingNetworkId = null;

    public string $createDisplayName = '';

    public string $createDockerNetworkName = '';

    public string $createDriver = 'bridge';

    public ?string $createSubnet = null;

    public ?string $createGateway = null;

    public bool $createInternal = false;

    public bool $createProxyAccess = false;

    public string $editDisplayName = '';

    public ?array $scanSummary = null;

    public ?string $refreshWarning = null;

    public bool $isRefreshingNetworks = false;

    protected $listeners = [
        'refreshDestinationNetworks' => 'scan',
    ];

    protected function rules(): array
    {
        return [
            'createDisplayName' => ['required', 'string', 'max:255'],
            'createDockerNetworkName' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
            'createDriver' => ['required', 'in:bridge'],
            'createSubnet' => ['nullable', 'string', 'max:255'],
            'createGateway' => ['nullable', 'string', 'max:255'],
            'createInternal' => ['boolean'],
            'createProxyAccess' => ['boolean'],
            'editDisplayName' => ['required', 'string', 'max:255'],
        ];
    }

    public function mount(string $server_uuid, array $server_options = [])
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->serverOptions = $server_options;
            $this->selectedInventoryServerUuid = $this->server->uuid;
            $this->createDockerNetworkName = $this->generateDraftNetworkName();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function refreshNetworksInBackground(): void
    {
        $this->isRefreshingNetworks = true;
        $this->refreshNetworks();
        $this->isRefreshingNetworks = false;
    }

    public function updatedSearch(): void
    {
        $this->closeInspect();
    }

    public function updatedFilter(): void
    {
        $this->closeInspect();
    }

    public function scan(): void
    {
        $this->authorize('update', $this->server);
        $this->refreshNetworks(force: true, dispatchSuccess: true);
    }

    public function createNetwork(): void
    {
        $this->authorize('update', $this->server);
        $this->validateOnly('createDisplayName');
        $this->validateOnly('createDockerNetworkName');
        $this->validateOnly('createDriver');

        try {
            $network = app(DockerNetworkManager::class)->create($this->server, [
                'display_name' => $this->createDisplayName,
                'docker_network_name' => $this->createDockerNetworkName,
                'driver' => $this->createDriver,
                'subnet' => $this->createSubnet,
                'gateway' => $this->createGateway,
                'internal' => $this->createInternal,
                'proxy_access' => $this->createProxyAccess,
            ]);

            $this->ensureDestination($network);
            $network->update(['available_during_creation' => true]);
            app(DestinationNetworkSynchronizer::class)->sync($this->server);

            $this->resetCreateForm();
            $this->selectedNetworkId = $network->id;
            $this->refreshNetworks(force: true);
            $this->dispatch('success', "Destination created. Docker network: {$network->docker_network_name}");
            $this->dispatch('destinationCatalogUpdated');
        } catch (DockerNetworkCreationException|DockerNetworkValidationException|ProxyNetworkReconciliationException $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function updatedCreateInternal(bool $internal): void
    {
        if ($internal) {
            $this->createProxyAccess = false;
        }
    }

    public function startEditing(int $networkId): void
    {
        $this->authorize('update', $this->server);
        $network = $this->networkForServer($networkId);

        if (! $this->canEditNetworkAlias($network)) {
            $this->dispatch('error', 'System network aliases cannot be edited.');

            return;
        }

        $this->editingNetworkId = $network->id;
        $this->editDisplayName = $network->display_name;
        $this->selectedNetworkId = $network->id;
    }

    public function updateProxyAccess(int $networkId, bool $proxyAccess): void
    {
        $this->authorize('update', $this->server);

        try {
            app(DockerNetworkManager::class)->updateProxyAccess($this->networkForServer($networkId), $proxyAccess);
            $this->dispatch('success', 'Proxy access updated.');
        } catch (DockerNetworkValidationException|ProxyNetworkReconciliationException $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function renameNetwork(): void
    {
        $this->authorize('update', $this->server);
        $this->validateOnly('editDisplayName');

        if (! $this->editingNetworkId) {
            return;
        }

        try {
            $network = $this->networkForServer($this->editingNetworkId);

            if (! $this->canEditNetworkAlias($network)) {
                $this->dispatch('error', 'System network aliases cannot be edited.');

                return;
            }

            $network = app(DockerNetworkManager::class)->renameDisplayName(
                $network,
                $this->editDisplayName,
            );

            $this->editingNetworkId = null;
            $this->editDisplayName = '';
            $this->selectedNetworkId = $network->id;
            $this->dispatch('success', 'Display name updated.');
        } catch (DockerNetworkValidationException $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function selectNetwork(int $networkId): void
    {
        $this->selectedNetworkId = $this->networkForServer($networkId)->id;
        $this->showInspectModal = true;
    }

    public function closeInspect(): void
    {
        $this->selectedNetworkId = null;
        $this->showInspectModal = false;
    }

    public function refreshInspect(): void
    {
        $this->authorize('update', $this->server);

        if (! $this->selectedNetworkId) {
            return;
        }

        try {
            app(DockerNetworkManager::class)->inspect($this->networkForServer($this->selectedNetworkId));
            $this->dispatch('success', 'Inspect refreshed.');
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function deleteNetwork(int $networkId, ?string $password = null): bool|string
    {
        $this->authorize('update', $this->server);

        if (! verifyPasswordConfirmation($password ?? '', $this)) {
            return 'Password confirmation failed.';
        }

        try {
            $network = $this->networkForServer($networkId);
            $destination = $this->destinationForNetwork($network);

            if ($destination) {
                $this->authorize('delete', $destination);
            }

            app(DockerNetworkManager::class)->deleteWithDestination($network);
            $destination?->delete();
            $this->closeInspect();
            $this->refreshNetworks(force: true);
            $this->dispatch('success', 'Network deleted.');

            return true;
        } catch (DockerNetworkDeletionException $e) {
            $this->dispatch('error', $e->getMessage());

            return $e->getMessage();
        }
    }

    public function resetCreateForm(): void
    {
        $this->showCreateForm = false;
        $this->createDisplayName = '';
        $this->createDockerNetworkName = $this->generateDraftNetworkName();
        $this->createDriver = 'bridge';
        $this->createSubnet = null;
        $this->createGateway = null;
        $this->createInternal = false;
        $this->createProxyAccess = false;
    }

    public function containerCount(DockerNetwork $network): int
    {
        return count(data_get($network->last_inspect_data, 'containers', []));
    }

    public function proxyAccessLabel(DockerNetwork $network): string
    {
        if (! app(ProxyNetworkReconciler::class)->isReconciled($network)) {
            return 'Reconciliation required';
        }

        return $network->proxy_access === false ? 'Proxy access disabled' : 'Proxy access enabled';
    }

    public function proxyAccessType(DockerNetwork $network): string
    {
        if (! app(ProxyNetworkReconciler::class)->isReconciled($network)) {
            return 'warning';
        }

        return $network->proxy_access === false ? 'neutral' : 'success';
    }

    public function selectedNetwork(): ?DockerNetwork
    {
        if (! $this->selectedNetworkId) {
            return null;
        }

        return DockerNetwork::query()
            ->byServer($this->server)
            ->byKey($this->selectedNetworkId)
            ->first();
    }

    public function inspectIpamConfigs(DockerNetwork $network): array
    {
        $configs = data_get($network->last_inspect_data, 'ipam_configs');

        if (! is_array($configs) || $configs === []) {
            $configs = data_get($network->last_inspect_data, 'raw.IPAM.Config', []);
        }

        if (! is_array($configs) || $configs === []) {
            $configs = [[
                'Subnet' => $network->subnet,
                'Gateway' => $network->gateway,
                'IPRange' => $network->ip_range,
            ]];
        }

        return collect($configs)
            ->filter(fn ($config) => is_array($config))
            ->values()
            ->map(function (array $config, int $index) {
                $subnet = data_get($config, 'Subnet');
                $gateway = data_get($config, 'Gateway');
                $ipRange = data_get($config, 'IPRange');

                return [
                    'label' => $this->ipamConfigLabel($subnet, $gateway, $index),
                    'subnet' => filled($subnet) ? $subnet : 'Not configured',
                    'gateway' => filled($gateway) ? $gateway : 'Not configured',
                    'ip_range' => filled($ipRange)
                        ? $ipRange
                        : (filled($subnet) ? 'Automatic (entire subnet)' : 'Not configured'),
                    'aux_addresses' => data_get($config, 'AuxiliaryAddresses', []),
                ];
            })
            ->all();
    }

    public function canUseAsDestination(DockerNetwork $network): bool
    {
        return ! $this->server->isSwarm()
            && $network->is_active
            && ! $network->is_system
            && ! in_array($network->docker_network_name, $this->reservedNetworkNames(), true)
            && ! $this->destinationForNetwork($network);
    }

    public function canEditNetworkAlias(DockerNetwork $network): bool
    {
        return ! $network->is_system
            && ! in_array($network->docker_network_name, $this->reservedNetworkNames(), true);
    }

    public function canDeleteNetwork(DockerNetwork $network): bool
    {
        return $network->is_active
            && ! $network->is_system
            && ! in_array($network->docker_network_name, $this->reservedNetworkNames(), true);
    }

    public function canRemoveFromDestinations(DockerNetwork $network): bool
    {
        return ! $network->is_system
            && ! in_array($network->docker_network_name, $this->reservedNetworkNames(), true);
    }

    public function useAsDestination(int $networkId): void
    {
        $this->authorize('create', StandaloneDocker::class);

        $network = $this->networkForServer($networkId);

        if (! $this->canUseAsDestination($network)) {
            $this->dispatch('error', 'This network cannot be used as a Destination.');

            return;
        }

        if ($this->destinationForNetwork($network)) {
            $this->dispatch('error', 'Destination already configured for this network.');

            return;
        }

        StandaloneDocker::withoutEvents(function () use ($network) {
            (new StandaloneDocker)->forceFill([
                'uuid' => (string) new Cuid2,
                'name' => app(DestinationNameResolver::class)->fromNetwork($network),
                'network' => $network->docker_network_name,
                'server_id' => $this->server->id,
            ])->save();
        });
        $network->update(['available_during_creation' => true]);
        app(DestinationNetworkSynchronizer::class)->sync($this->server);

        $this->server->unsetRelation('standaloneDockers');
        $this->server->load('standaloneDockers');
        $this->dispatch('success', 'Destination configured.');
        $this->dispatch('destinationCatalogUpdated');
    }

    private function ensureDestination(DockerNetwork $network): StandaloneDocker
    {
        $destination = $this->destinationForNetwork($network);

        if ($destination) {
            return $destination;
        }

        return StandaloneDocker::withoutEvents(function () use ($network) {
            $destination = new StandaloneDocker;
            $destination->forceFill([
                'uuid' => (string) new Cuid2,
                'name' => app(DestinationNameResolver::class)->fromNetwork($network),
                'network' => $network->docker_network_name,
                'server_id' => $this->server->id,
            ])->save();

            $this->server->unsetRelation('standaloneDockers');

            return $destination;
        });
    }

    public function removeFromDestinations(int $networkId, ?string $password = null, array $selectedActions = []): bool|string
    {
        $network = $this->networkForServer($networkId);

        $destination = $this->destinationForNetwork($network);

        if (! $destination) {
            $this->dispatch('error', 'Destination is not configured for this network.');

            return 'Destination is not configured for this network.';
        }

        $this->authorize('delete', $destination);

        if (! $this->canRemoveFromDestinations($network)) {
            $this->dispatch('error', 'System networks cannot be removed from Destinations.');

            return 'System networks cannot be removed from Destinations.';
        }

        $deleteNetwork = in_array('deleteNetwork', $selectedActions, true);

        if (! $deleteNetwork && $destination->attachedTo()) {
            $this->dispatch('error', 'You must delete all resources before removing this destination.');

            return 'You must delete all resources before removing this destination.';
        }

        if ($deleteNetwork) {
            try {
                app(DockerNetworkManager::class)->deleteWithDestination($network);
            } catch (DockerNetworkDeletionException $e) {
                $this->dispatch('error', $e->getMessage());

                return $e->getMessage();
            }
        }

        $destination->delete();

        if (! $deleteNetwork) {
            $network->update(['available_during_creation' => false]);
        }

        $this->server->unsetRelation('standaloneDockers');
        $this->server->load('standaloneDockers');
        $this->dispatch('success', $deleteNetwork
            ? 'Destination and Docker network deleted.'
            : 'Destination removed. Docker network was kept.');
        $this->dispatch('destinationCatalogUpdated');

        return true;
    }

    public function roleLabel(DockerNetwork $network): string
    {
        return match ($network->network_role?->value) {
            'shared_external' => 'Shared',
            'resource_stack' => 'Resource',
            'default_destination' => 'Destination',
            'preview_stack' => 'Preview',
            'private_internal' => 'Private',
            'managed_custom' => 'Custom',
            'system' => 'System',
            default => 'Unknown',
        };
    }

    public function networks(): Collection
    {
        $query = DockerNetwork::query()
            ->byServer($this->server)
            ->orderByDesc('is_active')
            ->orderBy('display_name');

        match ($this->filter) {
            'managed' => $query->where('managed_by_coolify', true),
            'external' => $query->where('external', true)->where('is_system', false),
            'system' => $query->where('is_system', true),
            'active' => $query->where('is_active', true),
            default => null,
        };

        if (filled($this->search)) {
            $search = "%{$this->search}%";
            $query->where(function ($query) use ($search) {
                $query->where('display_name', 'like', $search)
                    ->orWhere('docker_network_name', 'like', $search)
                    ->orWhere('subnet', 'like', $search);
            });
        }

        return $query->get();
    }

    public function render()
    {
        return view('livewire.destination.docker-networks', [
            'networks' => $this->networks(),
            'selectedNetwork' => $this->selectedNetwork(),
            'serverIsFunctional' => $this->server->isFunctional(),
            'destinationsByNetwork' => $this->destinationsByNetwork(),
        ]);
    }

    public function reservedNetworkNames(): array
    {
        return app(DockerNetworkClassifier::class)->reservedNetworkNames();
    }

    private function destinationsByNetwork(): Collection
    {
        return $this->server->standaloneDockers()
            ->get()
            ->keyBy('network');
    }

    private function destinationForNetwork(DockerNetwork $network): ?StandaloneDocker
    {
        return $this->server->standaloneDockers()
            ->where('network', $network->docker_network_name)
            ->first();
    }

    private function networkForServer(int $networkId): DockerNetwork
    {
        return DockerNetwork::query()
            ->byServer($this->server)
            ->byKey($networkId)
            ->firstOrFail();
    }

    private function ipamConfigLabel(?string $subnet, ?string $gateway, int $index): string
    {
        $value = $subnet ?: $gateway;

        if (is_string($value) && str_contains($value, ':')) {
            return 'IPv6';
        }

        if ($index === 0) {
            return 'IPv4';
        }

        return 'IPAM '.($index + 1);
    }

    private function generateDraftNetworkName(): string
    {
        return 'coolify-net-'.substr((string) new Cuid2, 0, 12);
    }

    private function refreshNetworks(bool $force = false, bool $dispatchSuccess = false): void
    {
        if (! $this->server->isFunctional()) {
            $this->refreshWarning = 'Could not refresh Docker networks. Showing last known state.';

            return;
        }

        $result = app(DockerNetworkCatalogRefresher::class)->refresh($this->server, $force);
        $errors = $result->get('errors', []);

        $this->scanSummary = [
            'found' => $result->get('found', 0),
            'created' => $result->get('created', 0),
            'updated' => $result->get('updated', 0),
            'removed' => $result->get('removed', 0),
        ];

        if (count($errors) > 0) {
            $this->refreshWarning = 'Could not refresh Docker networks. Showing last known state.';

            if ($dispatchSuccess) {
                $this->dispatch('warning', $this->refreshWarning);
            }

            return;
        }

        $this->refreshWarning = null;

        foreach (DockerNetwork::query()->byServer($this->server)->where('is_active', true)->get() as $network) {
            try {
                app(ProxyNetworkReconciler::class)->detectDrift($network);
            } catch (ProxyNetworkReconciliationException) {
                $inspectData = $network->last_inspect_data ?: [];
                $inspectData['proxy_access_reconciled'] = false;
                $inspectData['proxy_access_checked_at'] = now()->toISOString();
                $network->update(['last_inspect_data' => $inspectData]);
                $this->refreshWarning = 'Some proxy network states could not be verified. Reconciliation may be required.';
            }
        }

        if ($dispatchSuccess) {
            $this->dispatch('success', 'Networks refreshed.');
        }
    }
}
