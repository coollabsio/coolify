<div class="application-settings-form w-full">
    <x-slot:title>Clusters | Coolify</x-slot>

    <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Clusters</h1>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">Group Nodes in private, encrypted networks.</p>
        </div>
        <div class="flex w-fit shrink-0 items-center gap-2">
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
            @can('create', App\Models\Node::class)
                <a class="button button-highlighted" href="{{ route('node.onboarding') }}" {{ wireNavigate() }}>
                    <x-reicon name="plus" class="size-3.5" />
                    Add node
                </a>
            @endcan
        </div>
    </header>

    @if ($clusters->isEmpty())
        <x-empty title="No clusters yet" description="Create a cluster, then add Nodes to it." icon-name="layers" />
    @else
        @php
            $clusterRows = $clusters->map(fn ($cluster) => [
                'uuid' => $cluster->uuid,
                'name' => $cluster->name,
                'description' => $cluster->description ?? '',
                'cidr' => $cluster->cidr,
                'status' => $cluster->networkStatusLabel(),
                'statusType' => $cluster->networkStatusBadgeType(),
                'nodesCount' => $cluster->nodes_count,
                'href' => route('node-cluster.show', ['cluster_uuid' => $cluster->uuid]),
            ])->values();
        @endphp

        <div x-data="{
            search: '',
            viewMode: localStorage.getItem('coolify-node-clusters-view') || 'table',
            items: @js($clusterRows),
            get filteredItems() {
                const query = this.search.trim().toLowerCase();
                return query ? this.items.filter(item => Object.values(item).some(value => String(value || '').toLowerCase().includes(query))) : this.items;
            },
            matches(values) {
                const query = this.search.trim().toLowerCase();
                return !query || values.some(value => String(value || '').toLowerCase().includes(query));
            },
            setViewMode(mode) {
                this.viewMode = mode;
                localStorage.setItem('coolify-node-clusters-view', mode);
            }
        }">
            @include('livewire.shared.list-search-controls', ['placeholder' => 'Search clusters', 'singular' => 'cluster', 'plural' => 'clusters'])

            <div x-cloak x-show="viewMode === 'grid'" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($clusters as $cluster)
                    <a wire:key="cluster-grid-{{ $cluster->uuid }}"
                        x-show="matches(@js([$cluster->name, $cluster->description ?? '', $cluster->cidr, $cluster->networkStatusLabel()]))"
                        href="{{ route('node-cluster.show', ['cluster_uuid' => $cluster->uuid]) }}" {{ wireNavigate() }}
                        class="group flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.05] dark:hover:border-white/[0.14]">
                        <div class="flex items-start gap-3">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                <x-reicon name="layers" class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h2 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">{{ $cluster->name }}</h2>
                                @if (filled($cluster->description))
                                    <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $cluster->description }}</p>
                                @endif
                            </div>
                            <x-status-badge :status="$cluster->networkStatusLabel()" :type="$cluster->networkStatusBadgeType()" />
                        </div>
                        <div class="mt-auto flex items-center justify-between gap-3 pt-4">
                            <span class="truncate font-mono text-[11px] text-neutral-500 dark:text-fg-dim">{{ $cluster->cidr }}</span>
                            <span class="shrink-0 text-[11px] text-neutral-500 dark:text-fg-dim">{{ $cluster->nodes_count }} {{ Str::plural('node', $cluster->nodes_count) }}</span>
                        </div>
                    </a>
                @endforeach
            </div>

            <div x-show="viewMode === 'table'" class="overflow-x-auto rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
                <div class="grid min-w-[680px] grid-cols-[minmax(0,1fr)_10rem_9rem_7rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                    <div>Cluster</div>
                    <div>Network</div>
                    <div>Status</div>
                    <div>Nodes</div>
                </div>
                <template x-for="cluster in filteredItems" :key="cluster.uuid">
                    <a :href="cluster.href" {{ wireNavigate() }} class="grid min-h-14 min-w-[680px] grid-cols-[minmax(0,1fr)_10rem_9rem_7rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.1] dark:bg-white/[0.035] dark:text-fg-dim">
                                <x-reicon name="layers" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-[13px] font-semibold text-black dark:text-fg" x-text="cluster.name"></p>
                                <p x-show="cluster.description" class="truncate text-[11px] text-neutral-500 dark:text-fg-faint"
                                    x-text="cluster.description"></p>
                            </div>
                        </div>
                        <div class="truncate font-mono text-[11px] text-neutral-600 dark:text-fg-dim" x-text="cluster.cidr"></div>
                        <div class="flex items-center gap-2 text-[11px] font-medium text-neutral-600 dark:text-fg-dim">
                            <span class="size-2 shrink-0 rounded-full"
                                :class="{
                                    'bg-green-500 dark:bg-green-400': cluster.statusType === 'success',
                                    'bg-orange-500 dark:bg-warning': cluster.statusType === 'warning',
                                    'bg-red-500 dark:bg-red-400': cluster.statusType === 'error',
                                    'bg-neutral-400 dark:bg-neutral-500': cluster.statusType === 'neutral',
                                }"></span>
                            <span x-text="cluster.status"></span>
                        </div>
                        <div class="text-[11px] text-neutral-600 dark:text-fg-dim"><span x-text="cluster.nodesCount"></span> <span x-text="cluster.nodesCount === 1 ? 'node' : 'nodes'"></span></div>
                    </a>
                </template>
            </div>

            @include('livewire.shared.list-search-empty', ['label' => 'clusters'])
        </div>
    @endif

    @if ($nodes->isNotEmpty())
        <section class="mt-8">
            @php
                $sentinelUpgradeRunning = in_array(data_get($sentinelUpgradeSummary, 'status'), ['queued', 'running'], true);
            @endphp
            <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <h2 class="text-[15px]! font-semibold!">Nodes</h2>
                    <p class="mt-0.5 text-[12px] text-neutral-500 dark:text-fg-dim">Hosts that can run cluster applications.</p>
                </div>
                @if ($sentinelUpgradeNodes->isNotEmpty() && ! $sentinelUpgradeRunning)
                    @can('manageSentinel', $sentinelUpgradeNodes->first())
                        <div class="w-fit shrink-0">
                            <x-modal-confirmation title="Upgrade Sentinel on all Nodes?" buttonTitle="Upgrade all"
                                submitAction="upgradeAllSentinels" :actions="[
                                    'Sentinel ' . data_get($sentinelRelease, 'version') . ' is installed over SSH on ' . $sentinelUpgradeNodes->count() . ' ' . Str::plural('Node', $sentinelUpgradeNodes->count()) . ', one at a time.',
                                    'The upgrade stops at the first Node that fails. That Node is restored to its previous version.',
                                ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Upgrade all" />
                        </div>
                    @endcan
                @endif
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
            <div
                class="overflow-x-auto rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
                <div
                    class="grid min-w-[640px] grid-cols-[minmax(0,1fr)_9rem_10rem_8rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                    <div>Node</div>
                    <div>IP address</div>
                    <div>Cluster</div>
                    <div>Status</div>
                </div>
                @foreach ($nodes as $node)
                    <a wire:key="node-{{ $node->uuid }}" href="{{ route('node.show', ['node_uuid' => $node->uuid]) }}"
                        {{ wireNavigate() }}
                        class="grid min-h-14 min-w-[640px] grid-cols-[minmax(0,1fr)_9rem_10rem_8rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.1] dark:bg-white/[0.035] dark:text-fg-dim">
                                <x-reicon name="servers" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-[13px] font-semibold text-black dark:text-fg">{{ $node->name }}</p>
                                @if (filled($node->description))
                                    <p class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $node->description }}</p>
                                @endif
                            </div>
                        </div>
                        <div class="truncate font-mono text-[12px] text-neutral-600 dark:text-fg-dim">{{ $node->ip }}</div>
                        <div class="truncate text-[12px] text-neutral-600 dark:text-fg-dim">
                            {{ $node->cluster?->name ?? 'Unassigned' }}
                        </div>
                        <div class="flex flex-col items-start gap-1">
                            <x-status-badge :status="$node->is_usable ? 'Ready' : 'Not ready'"
                                :type="$node->is_usable ? 'success' : 'warning'" />
                            @if ($node->needsSentinelUpgrade($sentinelRelease))
                                <x-status-badge status="Upgrade available" type="warning" />
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</div>
