<div class="application-settings-form w-full">
    <x-slot:title>Clusters | Coolify</x-slot>

    <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Clusters</h1>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">Group Nodes in private full-mesh networks.</p>
        </div>
        @can('create', App\Models\Node::class)
            <div class="flex w-fit shrink-0 items-center gap-2">
                <a class="button button-highlighted" href="{{ route('node.onboarding') }}" {{ wireNavigate() }}>
                    <x-reicon name="plus" class="size-3.5" />
                    Add Node
                </a>
                @can('create', App\Models\NodeCluster::class)
                <x-modal-input title="New Node Cluster" :wireIgnore="false">
                    <x-slot:content>
                        <button type="button" class="button button-highlighted">
                            <x-reicon name="plus" class="size-3.5" />
                            New cluster
                        </button>
                    </x-slot:content>
                    <form wire:submit="createCluster" class="flex flex-col gap-4">
                        <x-forms.input wire:model="name" label="Name" required />
                        <x-forms.input wire:model="description" label="Description" />
                        <x-forms.input wire:model="cidr" label="Private CIDR override" placeholder="10.240.0.0/24"
                            helper="Coolify assigns an unused private /24 when this field is empty." />
                        <div class="flex justify-end">
                            <x-forms.button type="submit" isHighlighted>Create cluster</x-forms.button>
                        </div>
                    </form>
                </x-modal-input>
                @endcan
            </div>
        @endcan
    </header>

    @if ($nodes->isNotEmpty())
        <section class="mb-6">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-[15px]! font-semibold!">Nodes</h2>
                    <p class="mt-0.5 text-[12px] text-neutral-500 dark:text-fg-dim">Podman hosts managed by host-native Sentinel and Flux.</p>
                </div>
                <x-status-badge label="Dev" />
            </div>
            <div class="overflow-x-auto rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
                <div class="grid min-w-[480px] grid-cols-[minmax(0,1fr)_8rem_9.5rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                    <div>Node</div>
                    <div>Role</div>
                    <div>Status</div>
                </div>
                @foreach ($nodes as $node)
                    <a wire:key="node-{{ $node->uuid }}" href="{{ route('node.show', ['node_uuid' => $node->uuid]) }}" {{ wireNavigate() }}
                        class="grid min-h-14 min-w-[480px] grid-cols-[minmax(0,1fr)_8rem_9.5rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.1] dark:bg-white/[0.035] dark:text-fg-dim">
                                <x-reicon name="servers" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-[13px] font-semibold text-black dark:text-fg">{{ $node->name }}</p>
                                <p class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $node->description ?: 'No description' }}</p>
                            </div>
                        </div>
                        <div class="text-[11px] font-medium text-neutral-600 dark:text-fg-dim">{{ str($node->role->value)->replace('-', ' ')->title() }}</div>
                        <div class="text-[11px] font-medium">
                            <x-status-badge :status="$node->is_usable ? 'Ready' : 'Validation required'" :type="$node->is_usable ? 'success' : 'warning'" />
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($clusters->isEmpty())
        <x-empty title="No clusters yet" description="Create the first Node cluster." icon-name="servers" />
    @else
        @php
            $clusterRows = $clusters->map(fn ($cluster) => [
                'uuid' => $cluster->uuid,
                'name' => $cluster->name,
                'description' => $cluster->description ?: 'No description',
                'cidr' => $cluster->cidr,
                'status' => str($cluster->network_status)->replace('_', ' ')->title()->toString(),
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
                        x-show="matches(@js([$cluster->name, $cluster->description ?: 'No description', $cluster->cidr, $cluster->network_status]))"
                        href="{{ route('node-cluster.show', ['cluster_uuid' => $cluster->uuid]) }}" {{ wireNavigate() }}
                        class="group flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.05] dark:hover:border-white/[0.14]">
                        <div class="flex items-start gap-3">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                <x-reicon name="servers" class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h2 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">{{ $cluster->name }}</h2>
                                <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $cluster->description ?: 'No description' }}</p>
                            </div>
                            <x-status-badge :status="str($cluster->network_status)->replace('_', ' ')->title()" type="neutral" />
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
                    <div>Cluster</div><div>Network</div><div>Status</div><div>Nodes</div>
                </div>
                <template x-for="cluster in filteredItems" :key="cluster.uuid">
                    <a :href="cluster.href" {{ wireNavigate() }} class="grid min-h-14 min-w-[680px] grid-cols-[minmax(0,1fr)_10rem_9rem_7rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.1] dark:bg-white/[0.035] dark:text-fg-dim"><x-reicon name="servers" class="size-4" /></div>
                            <div class="min-w-0"><p class="truncate text-[13px] font-semibold text-black dark:text-fg" x-text="cluster.name"></p><p class="truncate text-[11px] text-neutral-500 dark:text-fg-faint" x-text="cluster.description"></p></div>
                        </div>
                        <div class="truncate font-mono text-[11px] text-neutral-600 dark:text-fg-dim" x-text="cluster.cidr"></div>
                        <div class="text-[11px] font-medium text-neutral-600 dark:text-fg-dim" x-text="cluster.status"></div>
                        <div class="text-[11px] text-neutral-600 dark:text-fg-dim"><span x-text="cluster.nodesCount"></span> <span x-text="cluster.nodesCount === 1 ? 'node' : 'nodes'"></span></div>
                    </a>
                </template>
            </div>

            @include('livewire.shared.list-search-empty', ['label' => 'clusters'])
        </div>
    @endif
</div>
