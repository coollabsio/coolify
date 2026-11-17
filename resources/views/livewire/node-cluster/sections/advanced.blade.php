@php
    $canUpdateCluster = auth()->user()->can('update', $cluster);
    $networkActivated = $cluster->hasActivatedNetwork();
    $cidrLocked = $networkActivated || $cluster->nodes()->exists();
@endphp

<x-application.settings-section id="node-cluster-private-network-section" title="Private network"
    helper="The private CIDR can change only while the cluster has no servers. The network settings are locked after activation.">
    <form wire:submit="saveSettings" class="flex flex-col gap-4">
        @if ($canUpdateCluster)
            <x-unsaved-bar action="saveSettings" targets="cidr,wireguardInterface,wireguardPort" />
        @endif
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <x-forms.input id="cidr" label="Private CIDR" required :disabled="$cidrLocked"
                canGate="update" :canResource="$cluster"
                :helper="$networkActivated ? 'The network is active, so the CIDR is locked.' : ($cidrLocked ? 'Remove all servers to change the CIDR.' : null)" />
            <x-forms.input id="wireguardInterface" label="WireGuard interface" required canGate="update"
                :canResource="$cluster" :disabled="$networkActivated" />
            <x-forms.input id="wireguardPort" type="number" min="1" max="65535" label="UDP port" required
                canGate="update" :canResource="$cluster" :disabled="$networkActivated" />
        </div>
    </form>
</x-application.settings-section>

<x-application.settings-section id="node-cluster-deployment-limits-section" title="Deployment limits"
    helper="Block new deployments when a selected server is unavailable, its resource data is stale, or usage reaches a limit. A value of 100 blocks only when that resource is fully used. Running applications are not stopped.">
    <form wire:submit="savePressurePolicy" class="flex flex-col gap-4">
        @if ($canUpdateCluster)
            <x-unsaved-bar action="savePressurePolicy" targets="cpuPressureThreshold,memoryPressureThreshold,diskPressureThreshold,resourceStaleAfterMinutes" />
        @endif
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-forms.input id="cpuPressureThreshold" type="number" min="1" max="100" label="CPU limit (%)" required
                canGate="update" :canResource="$cluster" />
            <x-forms.input id="memoryPressureThreshold" type="number" min="1" max="100" label="Memory limit (%)"
                required canGate="update" :canResource="$cluster" />
            <x-forms.input id="diskPressureThreshold" type="number" min="1" max="100" label="Disk limit (%)" required
                canGate="update" :canResource="$cluster" />
            <x-forms.input id="resourceStaleAfterMinutes" type="number" min="1" max="60"
                label="Data max age (minutes)" required canGate="update" :canResource="$cluster" />
        </div>
    </form>
</x-application.settings-section>

@php
    $currentImageStorage = $cluster->imageStorage();
@endphp
<x-application.settings-section id="node-cluster-image-storage-section" title="Image storage"
    helper="Where the images of Git applications are stored. By default they stay on the servers: each server copies an image from the server that has it.">
    <form wire:submit="saveImageStorage" class="flex flex-col gap-4">
        @if ($canUpdateCluster)
            <x-unsaved-bar action="saveImageStorage" targets="imageStorage,imageRepository,imageRegistryCredentialUuid" />
        @endif
        <p class="text-sm text-neutral-600 dark:text-fg-dim">
            Now: <span class="font-medium text-black dark:text-fg">{{ match ($currentImageStorage) {
                \App\Enums\NodeImageStorage::EXTERNAL => 'External registry ('.$cluster->image_repository.')',
                \App\Enums\NodeImageStorage::LOCAL => 'On the servers',
                null => $cluster->localImageStorageError(),
            } }}</span>
        </p>
        <x-forms.listbox id="imageStorage" label="Storage" canGate="update" :canResource="$cluster" required
            :options="[
                ['value' => 'local', 'label' => 'On the servers (recommended)', 'description' => 'No setup. Each server copies an image from the server that has it, and only the missing layers move. An image is not available while the only server that has it is offline.'],
                ['value' => 'external', 'label' => 'External registry', 'description' => 'A registry of your team, for example GHCR, Docker Hub, or Harbor. It stays available when a server is offline.'],
            ]" />
        <div x-cloak x-show="$wire.imageStorage === 'external'" class="grid gap-4 lg:grid-cols-2">
            <x-forms.input id="imageRepository" label="Repository" placeholder="ghcr.io/your-org/coolify-builds"
                canGate="update" :canResource="$cluster"
                helper="One repository for every application of the cluster. Each build gets the tag &lt;application&gt;-&lt;build&gt;. Coolify does not delete old tags." />
            <x-forms.listbox id="imageRegistryCredentialUuid" label="Registry credential" canGate="update"
                :canResource="$cluster" :options="$registryCredentialOptions"
                helper="The login that pushes and pulls. It needs write access to the repository. Add one in Keys & Tokens, Registry Credentials." />
        </div>
    </form>
</x-application.settings-section>

<x-application.settings-section id="node-cluster-troubleshooting-section" title="Troubleshooting"
    helper="Recent network operations of this cluster. A repair over SSH shows here only when the network is degraded or failed, or a server cannot reach Coolify." flush>
    @if ($cluster->hasNetworkStatus())
        <x-slot:badges>
            <x-status-badge :status="$cluster->networkStatusLabel()" :type="$cluster->networkStatusBadgeType()" />
        </x-slot:badges>
    @endif
    @if ($canUpdateCluster && $networkNeedsRepair)
        <x-slot:actions>
            <x-modal-confirmation title="Repair cluster network over SSH?" buttonTitle="Repair network over SSH"
                submitAction="repairNetwork" :actions="[
                    'Use this only when a network change cut the servers off from Coolify, so a normal sync cannot reach them.',
                    'On each server, Coolify connects over SSH and restores the last WireGuard configuration and cluster firewall rules that worked.',
                    'Only the Coolify cluster firewall table changes. Other firewall rules on the server stay as they are.',
                    'Corrosion, the internal DNS, and Sentinel restart.',
                    'Then run Sync network to apply the current settings again.',
                ]" :confirmWithText="false" :confirmWithPassword="false"
                warningMessage="The private network between the servers stops briefly. Firewall rules that you changed after the last successful sync are missing until the next sync."
                step2ButtonText="Repair network" />
        </x-slot:actions>
    @endif

    <div class="border-b border-neutral-200 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
        Recent operations
    </div>
    @forelse ($operations as $operation)
        @php
            $operationStatus = $operation->status->value;
            $operationStatusType = match ($operationStatus) {
                'succeeded' => 'success',
                'failed', 'timed_out' => 'error',
                'cancelled' => 'neutral',
                default => 'warning',
            };
        @endphp
        <div wire:key="cluster-operation-{{ $operation->uuid }}"
            class="flex min-h-12 items-center justify-between gap-3 border-b border-neutral-200 px-4 py-2 last:border-b-0 dark:border-white/[0.07]">
            <div class="min-w-0">
                <p class="truncate text-[13px] font-medium text-black dark:text-fg">
                    {{ str($operation->command_type)->replace('.v1', '')->replace('.', ' ')->title() }}
                </p>
                <p class="truncate text-[11px] text-neutral-500 dark:text-fg-dim">
                    {{ $operation->node->name }} · {{ $operation->created_at->diffForHumans() }}
                </p>
            </div>
            <x-status-badge :status="str($operationStatus)->replace('_', ' ')->title()" :type="$operationStatusType" />
        </div>
    @empty
        <p class="px-4 py-3 text-[13px] text-neutral-500 dark:text-fg-dim">No operations yet.</p>
    @endforelse
</x-application.settings-section>
