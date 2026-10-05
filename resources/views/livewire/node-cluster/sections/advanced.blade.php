@php
    $canUpdateCluster = auth()->user()->can('update', $cluster);
    $networkActivated = $cluster->hasActivatedNetwork();
@endphp

<x-application.settings-section id="node-cluster-private-network-section" title="Private network"
    helper="The private CIDR can change only before the network is activated.">
    <form wire:submit="saveSettings" class="flex flex-col gap-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <x-forms.input id="cidr" label="Private CIDR" required :disabled="$networkActivated"
                canGate="update" :canResource="$cluster"
                :helper="$networkActivated ? 'The network is active, so the CIDR is locked.' : null" />
            <x-forms.input id="wireguardInterface" label="WireGuard interface" required canGate="update"
                :canResource="$cluster" />
            <x-forms.input id="wireguardPort" type="number" min="1" max="65535" label="UDP port" required
                canGate="update" :canResource="$cluster" />
        </div>
        @if ($canUpdateCluster)
            <div class="flex">
                <x-forms.button type="submit">Save</x-forms.button>
            </div>
        @endif
    </form>
</x-application.settings-section>

<x-application.settings-section id="node-cluster-deployment-limits-section" title="Deployment limits"
    helper="Block new deployments when a selected server is unavailable, its resource data is stale, or usage reaches a limit. A value of 100 blocks only when that resource is fully used. Running applications are not stopped.">
    <form wire:submit="savePressurePolicy" class="flex flex-col gap-4">
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
        @if ($canUpdateCluster)
            <div class="flex">
                <x-forms.button type="submit">Save</x-forms.button>
            </div>
        @endif
    </form>
</x-application.settings-section>

<x-application.settings-section id="node-cluster-troubleshooting-section" title="Troubleshooting"
    helper="Use repair only when servers lost their private network connection and a sync cannot reach them." flush>
    @if ($canUpdateCluster)
        <x-slot:actions>
            <x-modal-confirmation title="Repair cluster network?" buttonTitle="Repair network over SSH"
                submitAction="repairNetwork" :actions="[
                    'The last known good network and firewall state is restored on every server over SSH.',
                    'Run a network sync afterwards to verify the current state.',
                ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Repair network" />
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
