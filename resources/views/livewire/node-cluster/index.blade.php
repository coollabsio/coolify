<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <x-slot:title>Node clusters | Coolify</x-slot>
    <div><h1>Node clusters</h1><p class="mt-1 text-sm text-neutral-500 dark:text-fg-dim">Group Nodes in private full-mesh networks.</p></div>
    <x-application.settings-section title="Create cluster" helper="Coolify assigns an unused private /24 when CIDR is empty.">
        <form wire:submit="createCluster" class="flex flex-col gap-4">
            <x-forms.input wire:model="name" label="Name" required />
            <x-forms.input wire:model="description" label="Description" />
            <x-forms.input wire:model="cidr" label="Private CIDR override" placeholder="10.240.0.0/24" />
            <div><x-forms.button type="submit">Create cluster</x-forms.button></div>
        </form>
    </x-application.settings-section>
    <x-application.settings-section title="Clusters">
        <div class="flex flex-col gap-3">
            @forelse ($clusters as $cluster)
                <a wire:key="cluster-{{ $cluster->uuid }}" href="{{ route('node-cluster.show', ['cluster_uuid' => $cluster->uuid]) }}" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-neutral-200 px-4 py-3 dark:border-white/[0.08]">
                    <div><p class="font-medium text-black dark:text-fg">{{ $cluster->name }}</p><p class="font-mono text-xs text-neutral-500 dark:text-fg-dim">{{ $cluster->cidr }}</p></div>
                    <div class="flex items-center gap-2"><x-status-badge :status="str($cluster->network_status)->title()" type="neutral" /><span class="text-xs">{{ $cluster->nodes_count }} Nodes</span></div>
                </a>
            @empty
                <x-empty size="sm" title="No clusters" description="Create the first Node cluster." icon-name="servers" />
            @endforelse
        </div>
    </x-application.settings-section>
</div>
