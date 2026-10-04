@php
    $canUpdateCluster = auth()->user()->can('update', $cluster);
    $canCreateNode = auth()->user()->can('create', App\Models\Node::class);
    $nodeGridClasses = 'grid min-w-[560px] grid-cols-[minmax(0,1fr)_8rem_7rem_7rem_5rem] items-center gap-3 px-4';
@endphp

<x-application.settings-section id="node-cluster-nodes-section" title="Nodes"
    helper="Nodes in this cluster share an encrypted private network. Each Node gets a stable private IP." flush>
    @if ($canCreateNode || ($canUpdateCluster && $availableNodes->isNotEmpty()))
        <x-slot:actions>
            @if ($canCreateNode)
                <a class="button" href="{{ route('node.onboarding') }}" {{ wireNavigate() }}>
                    <x-reicon name="plus" class="size-3.5" />
                    Connect new node
                </a>
            @endif
            @if ($canUpdateCluster && $availableNodes->isNotEmpty())
                <x-modal-input title="Add node to cluster" :wireIgnore="false">
                    <x-slot:content>
                        <button type="button" class="button button-highlighted">
                            <x-reicon name="plus" class="size-3.5" />
                            Add node
                        </button>
                    </x-slot:content>
                    <form wire:submit="assignNode" class="flex flex-col gap-4">
                        <x-forms.listbox id="nodeUuid" label="Node" placeholder="Select a node" required portal
                            :options="$availableNodes->map(fn ($node) => ['value' => $node->uuid, 'label' => $node->name])->values()->all()" />
                        <div class="flex justify-end">
                            <x-forms.button type="submit" isHighlighted>Add node</x-forms.button>
                        </div>
                    </form>
                </x-modal-input>
            @endif
        </x-slot:actions>
    @endif

    @if ($nodes->isEmpty())
        <div class="p-4">
            <x-empty size="sm" title="No nodes in this cluster"
                description="Add a node to start the private network." icon-name="servers">
                @if ($canCreateNode)
                    <x-slot:actions>
                        <a class="button button-highlighted" href="{{ route('node.onboarding') }}" {{ wireNavigate() }}>
                            <x-reicon name="plus" class="size-3.5" />
                            Connect new node
                        </a>
                    </x-slot:actions>
                @endif
            </x-empty>
        </div>
    @else
        <div class="overflow-x-auto">
            <div
                class="{{ $nodeGridClasses }} border-b border-neutral-200 bg-neutral-50 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                <div>Node</div>
                <div>Private IP</div>
                <div>Status</div>
                <div>Network</div>
                <div></div>
            </div>
            @foreach ($nodes as $node)
                @php
                    $networkState = $cluster->nodeNetworkState($node);
                @endphp
                <div wire:key="cluster-node-{{ $node->uuid }}"
                    class="{{ $nodeGridClasses }} min-h-14 border-b border-neutral-200 py-2.5 text-[12px] last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.1] dark:bg-white/[0.035] dark:text-fg-dim">
                            <x-reicon name="servers" class="size-4" />
                        </div>
                        <div class="min-w-0">
                            <a href="{{ route('node.show', ['node_uuid' => $node->uuid]) }}" {{ wireNavigate() }}
                                class="block truncate text-[13px] font-semibold text-black dark:text-fg">
                                {{ $node->name }}
                            </a>
                            @if (filled($node->description))
                                <p class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $node->description }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="truncate font-mono text-[12px] text-neutral-600 dark:text-fg-dim">
                        {{ $node->wireguard_ip ?? '—' }}
                    </div>
                    <div>
                        <x-status-badge :status="$node->is_usable ? 'Ready' : 'Not ready'"
                            :type="$node->is_usable ? 'success' : 'warning'" />
                    </div>
                    <div class="min-w-0">
                        <x-status-badge :status="match ($networkState) {
                            'converged' => 'In sync',
                            'error' => 'Failed',
                            default => 'Pending',
                        }" :type="match ($networkState) {
                            'converged' => 'success',
                            'error' => 'error',
                            default => 'warning',
                        }" />
                        @if ($networkState !== 'converged' && filled($node->network_error))
                            <p class="mt-1 truncate text-[11px] text-neutral-500 dark:text-fg-faint" title="{{ $node->network_error }}">
                                {{ $node->network_error }}
                            </p>
                        @endif
                    </div>
                    <div class="flex justify-end">
                        @if ($canUpdateCluster)
                            <x-forms.button wire:click="removeNode('{{ $node->uuid }}')"
                                wire:confirm="Remove {{ $node->name }} from this cluster? Its private network configuration will be removed, or when it is offline, as soon as it reconnects."
                                wire:loading.attr="disabled" wire:target="removeNode('{{ $node->uuid }}')">
                                Remove
                            </x-forms.button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-application.settings-section>
