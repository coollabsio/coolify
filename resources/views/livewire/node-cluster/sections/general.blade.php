@php
    $overview = [
        'Nodes ready' => $readyNodesCount.' / '.$nodesCount,
        'Private network' => $cluster->cidr,
        'Last synced' => $cluster->last_reconciled_at?->diffForHumans() ?? 'Never',
    ];
@endphp

<x-application.settings-section id="node-cluster-overview-section" title="Overview"
    helper="Private network state of the Nodes in this cluster.">
    @can('update', $cluster)
        <x-slot:actions>
            <x-forms.button wire:click="reconcileNetwork" wire:loading.attr="disabled" wire:target="reconcileNetwork">
                <x-reicon name="refresh" class="size-3.5" />
                Sync network
            </x-forms.button>
        </x-slot:actions>
    @endcan

    <dl class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Status</dt>
            <dd class="mt-1">
                <x-status-badge :status="$cluster->networkStatusLabel()" :type="$cluster->networkStatusBadgeType()" />
            </dd>
        </div>
        @foreach ($overview as $detailLabel => $detailValue)
            <div>
                <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">{{ $detailLabel }}</dt>
                <dd class="mt-1 text-sm font-medium text-neutral-950 dark:text-fg">{{ $detailValue }}</dd>
            </div>
        @endforeach
    </dl>
</x-application.settings-section>

<form wire:submit.prevent="saveSettings" class="flex flex-col gap-6">
    @can('update', $cluster)
        <x-unsaved-bar action="saveSettings" targets="name,description" />
    @endcan

    <x-application.settings-section id="node-cluster-general-section" title="General">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-forms.input id="name" label="Name" required canGate="update" :canResource="$cluster" />
            <x-forms.input id="description" label="Description" canGate="update" :canResource="$cluster" />
        </div>
    </x-application.settings-section>
</form>
