<?php

namespace App\Livewire\Project\Shared;

use App\Models\DockerNetwork;
use App\Models\NetworkAttachment;
use App\Services\Docker\DockerNetworkCatalogRefresher;
use App\Services\Docker\NetworkAttachableResolver;
use App\Services\Docker\NetworkAttachmentManager;
use App\Services\Docker\ResourceNetworkPlanner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Networking extends Component
{
    use AuthorizesRequests;

    public $resource;

    public ?int $selectedNetworkId = null;

    public ?int $editingAttachmentId = null;

    public string $aliases = '';

    public bool $isPrimary = false;

    public bool $isRequired = false;

    public bool $managedNetworkMode = false;

    public bool $showConnectForm = false;

    public ?string $refreshWarning = null;

    public bool $isRefreshingNetworks = false;

    protected function rules(): array
    {
        return [
            'selectedNetworkId' => ['required', 'integer'],
            'aliases' => ['nullable', 'string', 'max:1024'],
            'isPrimary' => ['boolean'],
            'isRequired' => ['boolean'],
        ];
    }

    public function mount(Model $resource, NetworkAttachmentManager $manager): void
    {
        $this->resource = $resource;
        $this->managedNetworkMode = $manager->managedNetworkModeEnabled($resource);
    }

    public function refreshNetworksInBackground(): void
    {
        $this->isRefreshingNetworks = true;
        $this->refreshKnownNetworks();
        $this->isRefreshingNetworks = false;
    }

    public function showConnectNetworkForm(): void
    {
        $this->resetForm();
        $this->showConnectForm = true;
    }

    public function connectNetwork(NetworkAttachmentManager $manager, ResourceNetworkPlanner $planner): void
    {
        $this->authorize('update', $this->resource);
        $this->validate();

        try {
            $network = $this->networkForResource((int) $this->selectedNetworkId);
            $manager->setManagedNetworkMode($this->resource, true);
            $this->managedNetworkMode = true;

            $attachment = $this->editingAttachmentId
                ? $manager->updateAttachment($this->attachmentForResource($this->editingAttachmentId), $this->payload())
                : $manager->createDesiredAttachment($this->resource, $network, $this->payload());

            $action = $planner->connect($attachment);
            $this->refreshKnownNetworks(force: true);

            if ($action['success']) {
                $this->resetForm();
                $this->dispatch('success', 'Network connected.');

                return;
            }

            $this->dispatch('error', $this->friendlyActionMessage($action));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function editAttachment(int $attachmentId): void
    {
        $attachment = $this->attachmentForResource($attachmentId);
        $this->editingAttachmentId = $attachment->id;
        $this->selectedNetworkId = $attachment->docker_network_id;
        $this->aliases = implode(', ', $attachment->aliases ?? []);
        $this->isPrimary = $attachment->is_primary;
        $this->isRequired = $attachment->is_required;
        $this->showConnectForm = true;
    }

    public function updateAttachment(NetworkAttachmentManager $manager): void
    {
        $this->authorize('update', $this->resource);
        $this->validateOnly('aliases');

        if (! $this->editingAttachmentId) {
            return;
        }

        $manager->updateAttachment($this->attachmentForResource($this->editingAttachmentId), $this->payload());
        $this->resetForm();
        $this->dispatch('success', 'Network attachment updated.');
    }

    public function setPrimary(int $attachmentId, NetworkAttachmentManager $manager): void
    {
        $this->authorize('update', $this->resource);
        $manager->setPrimary($this->attachmentForResource($attachmentId));
        $this->dispatch('success', 'Primary network updated.');
    }

    public function toggleRequired(int $attachmentId): void
    {
        $this->authorize('update', $this->resource);
        $attachment = $this->attachmentForResource($attachmentId);
        $attachment->update(['is_required' => ! $attachment->is_required]);
    }

    public function removeAttachment(int $attachmentId, NetworkAttachmentManager $manager): void
    {
        $this->authorize('update', $this->resource);

        try {
            $manager->deleteAttachmentConfiguration($this->attachmentForResource($attachmentId));
            $this->managedNetworkMode = $manager->syncManagedNetworkMode($this->resource);
            $this->refreshKnownNetworks(force: true);
            $this->dispatch('success', 'Network attachment removed.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function connectAttachment(int $attachmentId, ResourceNetworkPlanner $planner): void
    {
        $this->authorize('update', $this->resource);
        $action = $planner->connect($this->attachmentForResource($attachmentId));
        $this->refreshKnownNetworks(force: true);

        if ($action['success']) {
            $this->dispatch('success', $action['message']);

            return;
        }

        $this->dispatch('error', $action['message']);
    }

    public function disconnectAttachment(int $attachmentId, ResourceNetworkPlanner $planner, NetworkAttachmentManager $manager): void
    {
        $this->authorize('update', $this->resource);
        $action = $planner->disconnect($this->attachmentForResource($attachmentId));
        $this->managedNetworkMode = $manager->syncManagedNetworkMode($this->resource);
        $this->refreshKnownNetworks(force: true);

        if ($action['success']) {
            $this->dispatch('success', $action['message']);

            return;
        }

        $this->dispatch('error', $action['message']);
    }

    public function resetForm(): void
    {
        $this->selectedNetworkId = null;
        $this->editingAttachmentId = null;
        $this->aliases = '';
        $this->isPrimary = false;
        $this->isRequired = false;
        $this->showConnectForm = false;
    }

    public function availableNetworks(): Collection
    {
        $server = app(NetworkAttachableResolver::class)->resolveServer($this->resource);

        if (! $server) {
            return collect();
        }

        return DockerNetwork::query()
            ->byServer($server)
            ->orderByDesc('is_active')
            ->orderBy('display_name')
            ->get();
    }

    public function attachments(): Collection
    {
        return NetworkAttachment::query()
            ->with('dockerNetwork')
            ->where('attachable_type', $this->resource::class)
            ->where('attachable_id', $this->resource->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();
    }

    public function warnings(NetworkAttachment $attachment): array
    {
        $warnings = [];
        $network = $attachment->dockerNetwork;

        if (! $network?->is_active) {
            $warnings[] = 'This network is inactive and cannot be applied until it exists on the Docker host.';
        }

        if ($network?->is_system) {
            $warnings[] = 'This is a system network. Use with caution.';
        }

        if ($attachment->status?->value === 'missing_container') {
            $warnings[] = 'Could not find the running container for this resource.';
        }

        if ($attachment->is_required && $attachment->status?->value === 'missing_container') {
            $warnings[] = 'This connection is required, but the container was not found.';
        }

        return $warnings;
    }

    public function statusLabel(NetworkAttachment $attachment): string
    {
        return match ($attachment->status?->value) {
            'attached' => 'Connected',
            'desired' => 'Pending',
            'detached' => 'Disconnected',
            'missing_network' => 'Network missing',
            'missing_container' => 'Container not found',
            'failed' => 'Failed',
            default => 'Unknown',
        };
    }

    public function roleLabel(?DockerNetwork $network): string
    {
        return match ($network?->network_role?->value) {
            'shared_external' => 'Shared',
            'resource_stack' => 'Resource',
            'default_destination' => 'Default',
            'private_internal' => 'Private',
            'managed_custom' => 'Custom',
            'system' => 'System',
            default => 'Unknown',
        };
    }

    public function networkTypeLabel(?DockerNetwork $network): string
    {
        if (! $network) {
            return 'Unknown';
        }

        if ($network->is_system) {
            return 'System';
        }

        return $network->managed_by_coolify ? 'Managed' : 'External';
    }

    public function render()
    {
        $attachments = $this->attachments();

        return view('livewire.project.shared.networking', [
            'attachments' => $attachments,
            'availableNetworks' => $this->availableNetworks(),
            'managedNetworkMode' => $this->managedNetworkMode,
        ]);
    }

    private function networkForResource(int $networkId): DockerNetwork
    {
        $server = app(NetworkAttachableResolver::class)->resolveServer($this->resource);

        return DockerNetwork::query()
            ->byServer((int) $server?->id)
            ->byKey($networkId)
            ->firstOrFail();
    }

    private function attachmentForResource(int $attachmentId): NetworkAttachment
    {
        return NetworkAttachment::query()
            ->where('attachable_type', $this->resource::class)
            ->where('attachable_id', $this->resource->id)
            ->whereKey($attachmentId)
            ->firstOrFail();
    }

    private function payload(): array
    {
        return [
            'aliases' => $this->aliases,
            'is_primary' => $this->isPrimary,
            'is_required' => $this->isRequired,
        ];
    }

    private function friendlyActionMessage(array $action): string
    {
        return $action['message'] ?? 'Coolify could not connect this network.';
    }

    private function refreshKnownNetworks(bool $force = false): void
    {
        $server = app(NetworkAttachableResolver::class)->resolveServer($this->resource);

        if (! $server) {
            return;
        }

        $result = app(DockerNetworkCatalogRefresher::class)->refresh($server, $force);

        $this->refreshWarning = count($result->get('errors', [])) > 0
            ? 'Could not refresh Docker networks. Showing last known state.'
            : null;
    }
}
