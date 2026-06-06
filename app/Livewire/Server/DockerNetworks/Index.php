<?php

namespace App\Livewire\Server\DockerNetworks;

use App\Exceptions\DockerNetworkCreationException;
use App\Exceptions\DockerNetworkDeletionException;
use App\Exceptions\DockerNetworkImportException;
use App\Exceptions\DockerNetworkValidationException;
use App\Models\DockerNetwork;
use App\Models\Server;
use App\Services\Docker\DockerNetworkCatalogRefresher;
use App\Services\Docker\DockerNetworkClassifier;
use App\Services\Docker\DockerNetworkManager;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public string $filter = 'all';

    public string $search = '';

    public bool $showCreateForm = false;

    public bool $showImportForm = false;

    public ?int $selectedNetworkId = null;

    public ?int $editingNetworkId = null;

    public string $createDisplayName = '';

    public string $createDriver = 'bridge';

    public ?string $createSubnet = null;

    public ?string $createGateway = null;

    public bool $createInternal = false;

    public bool $createAttachable = true;

    public string $importNetworkName = '';

    public ?string $importDisplayName = null;

    public string $editDisplayName = '';

    public ?array $scanSummary = null;

    public ?string $refreshWarning = null;

    public bool $isRefreshingNetworks = false;

    protected function rules(): array
    {
        return [
            'createDisplayName' => ['required', 'string', 'max:255'],
            'createDriver' => ['required', 'in:bridge'],
            'createSubnet' => ['nullable', 'string', 'max:255'],
            'createGateway' => ['nullable', 'string', 'max:255'],
            'createInternal' => ['boolean'],
            'createAttachable' => ['boolean'],
            'importNetworkName' => ['required', 'string', 'max:255'],
            'importDisplayName' => ['nullable', 'string', 'max:255'],
            'editDisplayName' => ['required', 'string', 'max:255'],
        ];
    }

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
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
        $this->reset('selectedNetworkId');
    }

    public function updatedFilter(): void
    {
        $this->reset('selectedNetworkId');
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
        $this->validateOnly('createDriver');

        try {
            $network = app(DockerNetworkManager::class)->create($this->server, [
                'display_name' => $this->createDisplayName,
                'driver' => $this->createDriver,
                'subnet' => $this->createSubnet,
                'gateway' => $this->createGateway,
                'internal' => $this->createInternal,
                'attachable' => $this->createAttachable,
            ]);

            $this->resetCreateForm();
            $this->selectedNetworkId = $network->id;
            $this->refreshNetworks(force: true);
            $this->dispatch('success', "Network created successfully. Docker network: {$network->docker_network_name}");
        } catch (DockerNetworkCreationException|DockerNetworkValidationException $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function importNetwork(): void
    {
        $this->authorize('update', $this->server);
        $this->validateOnly('importNetworkName');

        try {
            $network = app(DockerNetworkManager::class)->import(
                $this->server,
                $this->importNetworkName,
                $this->importDisplayName,
            );

            $this->resetImportForm();
            $this->selectedNetworkId = $network->id;
            $this->refreshNetworks(force: true);
            $this->dispatch('success', 'Network imported successfully.');
        } catch (DockerNetworkImportException $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function startEditing(int $networkId): void
    {
        $network = $this->networkForServer($networkId);
        $this->editingNetworkId = $network->id;
        $this->editDisplayName = $network->display_name;
        $this->selectedNetworkId = $network->id;
    }

    public function renameNetwork(): void
    {
        $this->authorize('update', $this->server);
        $this->validateOnly('editDisplayName');

        if (! $this->editingNetworkId) {
            return;
        }

        try {
            $network = app(DockerNetworkManager::class)->renameDisplayName(
                $this->networkForServer($this->editingNetworkId),
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

    public function deleteNetwork(int $networkId): void
    {
        $this->authorize('update', $this->server);

        try {
            app(DockerNetworkManager::class)->delete($this->networkForServer($networkId));
            $this->selectedNetworkId = null;
            $this->refreshNetworks(force: true);
            $this->dispatch('success', 'Network deleted.');
        } catch (DockerNetworkDeletionException $e) {
            $this->dispatch('error', $this->deleteReasonLabel($e->getMessage()));
        }
    }

    public function resetCreateForm(): void
    {
        $this->showCreateForm = false;
        $this->createDisplayName = '';
        $this->createDriver = 'bridge';
        $this->createSubnet = null;
        $this->createGateway = null;
        $this->createInternal = false;
        $this->createAttachable = true;
    }

    public function resetImportForm(): void
    {
        $this->showImportForm = false;
        $this->importNetworkName = '';
        $this->importDisplayName = null;
    }

    public function containerCount(DockerNetwork $network): int
    {
        return count(data_get($network->last_inspect_data, 'containers', []));
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

    public function deleteEnabled(DockerNetwork $network): bool
    {
        return $network->managed_by_coolify
            && ! $network->external
            && ! $network->is_system
            && $network->is_active;
    }

    public function roleLabel(DockerNetwork $network): string
    {
        return match ($network->network_role?->value) {
            'shared_external' => 'Shared',
            'resource_stack' => 'Resource',
            'default_destination' => 'Default',
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
            'inactive' => $query->where('is_active', false),
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
        return view('livewire.server.docker-networks.index', [
            'networks' => $this->networks(),
            'selectedNetwork' => $this->selectedNetwork(),
            'serverIsFunctional' => $this->server->isFunctional(),
            'reservedImportNetworkNames' => $this->reservedImportNetworkNames(),
        ]);
    }

    public function reservedImportNetworkNames(): array
    {
        return array_merge(
            DockerNetworkClassifier::SYSTEM_NETWORK_NAMES,
            DockerNetworkClassifier::COOLIFY_DEFAULT_NETWORK_NAMES,
        );
    }

    private function networkForServer(int $networkId): DockerNetwork
    {
        return DockerNetwork::query()
            ->byServer($this->server)
            ->byKey($networkId)
            ->firstOrFail();
    }

    private function deleteReasonLabel(string $reason): string
    {
        return match ($reason) {
            'system_network' => 'Cannot delete system network.',
            'external_network' => 'Cannot delete external network.',
            'not_managed_by_coolify' => 'Cannot delete network not managed by Coolify.',
            'inactive_network' => 'Cannot delete inactive network.',
            'has_connected_containers' => 'Cannot delete network with connected containers.',
            'has_managed_attachments' => 'Cannot delete network with active managed attachments.',
            default => 'Cannot delete network.',
        };
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
            'marked_inactive' => $result->get('marked_inactive', 0),
        ];

        if (count($errors) > 0) {
            $this->refreshWarning = 'Could not refresh Docker networks. Showing last known state.';

            if ($dispatchSuccess) {
                $this->dispatch('warning', $this->refreshWarning);
            }

            return;
        }

        $this->refreshWarning = null;

        if ($dispatchSuccess) {
            $this->dispatch('success', 'Networks refreshed.');
        }
    }
}
