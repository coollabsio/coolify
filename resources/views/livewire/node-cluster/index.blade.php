<section data-testid="server-index-clusters" class="w-full">
    @php
        $sentinelUpgradeRunning = in_array(data_get($sentinelUpgradeSummary, 'status'), ['queued', 'running'], true);
    @endphp
    <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h2 class="text-[15px]! font-semibold!">Clusters</h2>
            <p class="mt-0.5 text-[12px] text-neutral-500 dark:text-fg-dim">Cluster servers share a private, encrypted network and run cluster applications.</p>
        </div>
        <div class="flex w-fit shrink-0 flex-wrap items-center gap-2">
            @if ($sentinelUpgradeNodes->isNotEmpty() && ! $sentinelUpgradeRunning)
                @can('manageSentinel', $sentinelUpgradeNodes->first())
                    <x-modal-confirmation title="Upgrade Sentinel on all cluster servers?" buttonTitle="Upgrade all"
                        submitAction="upgradeAllSentinels" :actions="[
                            'Sentinel ' . data_get($sentinelRelease, 'version') . ' is installed over SSH on ' . $sentinelUpgradeNodes->count() . ' ' . Str::plural('server', $sentinelUpgradeNodes->count()) . ', one at a time.',
                            'The upgrade stops at the first server that fails. That server is restored to its previous version.',
                        ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Upgrade all" />
                @endcan
            @endif
            @can('create', App\Models\NodeCluster::class)
                <x-modal-input title="New cluster" :wireIgnore="false">
                    <x-slot:content>
                        <button type="button" class="button">
                            <x-reicon name="plus" class="size-3.5" />
                            New cluster
                        </button>
                    </x-slot:content>
                    <form wire:submit="createCluster" class="flex flex-col gap-4">
                        <x-forms.input id="name" label="Name" required />
                        <x-forms.input id="description" label="Description" />
                        <x-forms.input id="cidr" label="Private CIDR" placeholder="10.240.0.0/24"
                            helper="Leave empty to let Coolify pick an unused private /24 network." />
                        <div class="flex justify-end">
                            <x-forms.button type="submit" isHighlighted>Create cluster</x-forms.button>
                        </div>
                    </form>
                </x-modal-input>
            @endcan
        </div>
    </div>

    @if ($sentinelUpgradeSummary !== null)
        <div @if ($sentinelUpgradeRunning) wire:poll.5s @endif
            class="mb-3 flex flex-wrap items-center gap-2 text-[12px] text-neutral-600 dark:text-fg-dim">
            <span>Sentinel upgrade</span>
            <x-status-badge :status="str(data_get($sentinelUpgradeSummary, 'status'))->title()->toString()"
                :type="match (data_get($sentinelUpgradeSummary, 'status')) { 'succeeded' => 'success', 'failed' => 'error', default => 'warning' }" />
            <span>{{ count(data_get($sentinelUpgradeSummary, 'upgraded', [])) }} upgraded</span>
            @if (filled(data_get($sentinelUpgradeSummary, 'failed_node_name')))
                <span>&middot; Stopped at {{ data_get($sentinelUpgradeSummary, 'failed_node_name') }}</span>
            @endif
            @if (filled(data_get($sentinelUpgradeSummary, 'error')))
                <p class="w-full text-red-600 dark:text-red-400">{{ data_get($sentinelUpgradeSummary, 'error') }}</p>
            @endif
        </div>
    @endif

    @if ($clusters->isEmpty() && $unassignedNodes->isEmpty())
        <x-empty title="No clusters yet" description="Create a cluster, then add cluster servers to it." icon-name="layers" />
    @endif

    <div class="flex flex-col gap-4">
        @foreach ($clusters as $cluster)
            <div wire:key="cluster-group-{{ $cluster->uuid }}" data-testid="cluster-group"
                class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
                <div class="flex flex-col gap-3 border-b border-neutral-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.08]">
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.1] dark:bg-white/[0.035] dark:text-fg-dim">
                            <x-reicon name="layers" class="size-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                <a href="{{ route('node-cluster.show', ['cluster_uuid' => $cluster->uuid]) }}" {{ wireNavigate() }}
                                    class="truncate text-[13px] font-semibold text-black hover:underline dark:text-fg">{{ $cluster->name }}</a>
                                <x-status-badge :status="$cluster->networkStatusLabel()" :type="$cluster->networkStatusBadgeType()" />
                            </div>
                            <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                {{ $cluster->nodes->count() }} {{ Str::plural('server', $cluster->nodes->count()) }}
                                <span class="font-mono">&middot; {{ $cluster->cidr }}</span>
                                @if (filled($cluster->description))
                                    &middot; {{ $cluster->description }}
                                @endif
                            </p>
                        </div>
                    </div>
                    <a href="{{ route('node-cluster.show', ['cluster_uuid' => $cluster->uuid]) }}" {{ wireNavigate() }}
                        class="button w-fit shrink-0">
                        <x-reicon name="settings" class="size-3.5" />
                        Cluster settings
                    </a>
                </div>
                @if ($cluster->nodes->isEmpty())
                    <div class="flex flex-col items-start gap-2 px-4 py-4 text-[12px] text-neutral-500 sm:flex-row sm:items-center sm:justify-between dark:text-fg-dim">
                        <span>No servers in this cluster yet.</span>
                        @can('update', $cluster)
                            <a href="{{ route('node-cluster.nodes', ['cluster_uuid' => $cluster->uuid]) }}" {{ wireNavigate() }}
                                class="button w-fit">
                                <x-reicon name="plus" class="size-3.5" />
                                Add server
                            </a>
                        @endcan
                    </div>
                @else
                    @include('livewire.node-cluster.partials.server-table', ['nodes' => $cluster->nodes, 'cluster' => $cluster])
                @endif
            </div>
        @endforeach

        @if ($unassignedNodes->isNotEmpty())
            <div wire:key="cluster-group-unassigned" data-testid="cluster-group-unassigned"
                class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
                <div class="border-b border-neutral-200 px-4 py-3 dark:border-white/[0.08]">
                    <p class="text-[13px] font-semibold text-black dark:text-fg">Not in a cluster</p>
                    <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                        {{ $unassignedNodes->count() }} {{ Str::plural('server', $unassignedNodes->count()) }}
                        &middot; Add them to a cluster from its settings to run cluster applications.
                    </p>
                </div>
                @include('livewire.node-cluster.partials.server-table', ['nodes' => $unassignedNodes, 'cluster' => null])
            </div>
        @endif
    </div>
</section>
