@php
    $canUpdateCluster = auth()->user()->can('update', $cluster);
    $canCreateNode = auth()->user()->can('create', App\Models\Node::class);
    $nodeGridClasses = 'grid min-w-[820px] grid-cols-[minmax(0,1fr)_7rem_7rem_8rem_16rem] items-center gap-3 px-4';
    $sentinelUpgradeRunning = in_array(data_get($sentinelUpgradeRun, 'status'), ['queued', 'running'], true);
    $canUpgradeSentinel = $canUpdateCluster && $sentinelUpgradeNodes->isNotEmpty() && ! $sentinelUpgradeRunning;
@endphp

<x-application.settings-section id="node-cluster-nodes-section" title="Servers"
    helper="Servers in this cluster share an encrypted private network." flush>
    @if ($canCreateNode || $canUpgradeSentinel || ($canUpdateCluster && $availableNodes->isNotEmpty()))
        <x-slot:actions>
            @if ($canUpgradeSentinel)
                <x-modal-confirmation title="Upgrade Sentinel in this cluster?" buttonTitle="Upgrade Sentinel"
                    submitAction="upgradeClusterSentinel" :actions="[
                        'Sentinel ' . data_get($sentinelRelease, 'version') . ' is installed over SSH on ' . $sentinelUpgradeNodes->count() . ' ' . Str::plural('server', $sentinelUpgradeNodes->count()) . ', one at a time.',
                        'Each server must reconnect to Coolify before the next one starts.',
                        'If a server does not reconnect within ' . Carbon\CarbonInterval::seconds(App\Actions\Node\UpgradeSentinel::reconnectTimeoutSeconds())->cascade()->forHumans() . ', its previous version is restored over SSH and the upgrade stops.',
                    ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Upgrade Sentinel"
                    warningMessage="The control connection of each server drops briefly while Sentinel restarts." />
            @endif
            @if ($canCreateNode)
                <a class="button" href="{{ route('node.onboarding') }}" {{ wireNavigate() }}>
                    <x-reicon name="plus" class="size-3.5" />
                    Connect new server
                </a>
            @endif
            @if ($canUpdateCluster && $availableNodes->isNotEmpty())
                <x-modal-input title="Add server to cluster" :wireIgnore="false">
                    <x-slot:content>
                        <button type="button" class="button button-highlighted">
                            <x-reicon name="plus" class="size-3.5" />
                            Add server
                        </button>
                    </x-slot:content>
                    <form wire:submit="assignNode" class="flex flex-col gap-4">
                        <x-forms.listbox id="nodeUuid" label="Server" placeholder="Select a server" required portal
                            :options="$availableNodes->map(fn ($node) => ['value' => $node->uuid, 'label' => $node->name])->values()->all()" />
                        <div class="flex justify-end">
                            <x-forms.button type="submit" isHighlighted>Add server</x-forms.button>
                        </div>
                    </form>
                </x-modal-input>
            @endif
        </x-slot:actions>
    @endif

    @if ($sentinelUpgradeRun !== null)
        <div data-testid="cluster-sentinel-upgrade" @if ($sentinelUpgradeRunning) wire:poll.5s @endif
            class="flex flex-col gap-2 border-b border-neutral-200 px-4 py-3 text-[12px] dark:border-white/[0.08]">
            <div class="flex flex-wrap items-center gap-2 text-neutral-600 dark:text-fg-dim">
                <span class="font-medium text-black dark:text-fg">Sentinel upgrade to {{ data_get($sentinelUpgradeRun, 'version') ?? 'the latest version' }}</span>
                <x-status-badge :status="str(data_get($sentinelUpgradeRun, 'status'))->title()->toString()"
                    :type="match (data_get($sentinelUpgradeRun, 'status')) { 'succeeded' => 'success', 'failed' => 'error', default => 'warning' }" />
                @if (filled(data_get($sentinelUpgradeRun, 'finished_at')))
                    <span>&middot; Finished {{ \Illuminate\Support\Carbon::parse(data_get($sentinelUpgradeRun, 'finished_at'))->diffForHumans() }}</span>
                @endif
            </div>
            @foreach (data_get($sentinelUpgradeRun, 'nodes', []) as $upgradeNode)
                @php
                    $upgradeOperation = $sentinelUpgradeOperations->get(data_get($upgradeNode, 'operation_id'));
                    [$upgradeLabel, $upgradeType] = match (true) {
                        $upgradeOperation === null && $sentinelUpgradeRunning => ['Waiting', 'neutral'],
                        $upgradeOperation === null => ['Not started', 'neutral'],
                        $upgradeOperation->status->value === 'running' => ['Installing', 'warning'],
                        $upgradeOperation->status->value === 'verifying' => ['Waiting for reconnect', 'warning'],
                        $upgradeOperation->status->value === 'succeeded' => ['Upgraded', 'success'],
                        data_get($upgradeOperation->result, 'rolled_back') === true => ['Rolled back', 'error'],
                        $upgradeOperation->status->isFinal() => ['Failed', 'error'],
                        default => ['Starting', 'warning'],
                    };
                @endphp
                <div wire:key="sentinel-upgrade-{{ data_get($upgradeNode, 'uuid') }}" class="flex flex-wrap items-center gap-2">
                    <x-status-badge :status="$upgradeLabel" :type="$upgradeType" />
                    <span class="font-medium text-black dark:text-fg">{{ data_get($upgradeNode, 'name') }}</span>
                    <span class="font-mono text-[11px] text-neutral-500 dark:text-fg-faint">{{ data_get($upgradeNode, 'previous_version') ?? 'unknown' }} &rarr; {{ data_get($sentinelUpgradeRun, 'version') }}</span>
                    @if (filled($upgradeOperation?->error))
                        <p class="w-full text-red-600 dark:text-red-400">{{ $upgradeOperation->error }}</p>
                    @endif
                </div>
            @endforeach
            @if (filled(data_get($sentinelUpgradeRun, 'failed_node_name')))
                <p class="text-neutral-600 dark:text-fg-dim">Stopped at {{ data_get($sentinelUpgradeRun, 'failed_node_name') }}. The servers after it were not touched.</p>
            @endif
            @if (filled(data_get($sentinelUpgradeRun, 'error')) && ! $sentinelUpgradeOperations->contains('error', data_get($sentinelUpgradeRun, 'error')))
                <p class="text-red-600 dark:text-red-400">{{ data_get($sentinelUpgradeRun, 'error') }}</p>
            @endif
        </div>
    @endif

    @if ($nodes->isEmpty())
        <div class="p-4">
            <x-empty size="sm" title="No servers in this cluster"
                description="Add a server to start the private network." icon-name="servers">
                @if ($canCreateNode)
                    <x-slot:actions>
                        <a class="button button-highlighted" href="{{ route('node.onboarding') }}" {{ wireNavigate() }}>
                            <x-reicon name="plus" class="size-3.5" />
                            Connect new server
                        </a>
                    </x-slot:actions>
                @endif
            </x-empty>
        </div>
    @else
        <div class="overflow-x-auto">
            <div
                class="{{ $nodeGridClasses }} border-b border-neutral-200 bg-neutral-50 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                <div>Server</div>
                <div>Status</div>
                <div>Network</div>
                <div>Roles</div>
                <div></div>
            </div>
            @foreach ($nodes as $node)
                @php
                    $networkState = $cluster->nodeNetworkState($node);
                    $ingressState = $cluster->nodeIngressState($node);
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
                    <div class="min-w-0">
                        <x-status-badge :status="match ($ingressState) {
                            'active' => 'Active',
                            'pending' => 'Pending',
                            default => 'Off',
                        }" :type="match ($ingressState) {
                            'active' => 'success',
                            'pending' => 'warning',
                            default => 'neutral',
                        }" />
                        @if ($cluster->registry?->node_id === $node->id)
                            <p class="mt-1 truncate text-[11px] text-neutral-500 dark:text-fg-faint">Image registry</p>
                        @endif
                        @if ($cluster->registry !== null && $node->canBuild())
                            <p class="mt-1 truncate text-[11px] {{ data_get($node->metadata, 'build_error') ? 'text-red-500' : 'text-neutral-500 dark:text-fg-faint' }}"
                                title="{{ data_get($node->metadata, 'build_error') }}">
                                {{ data_get($node->metadata, 'build_error') ? 'Builder error' : (data_get($node->metadata, 'builder_running') ? 'Builder' : 'Builder pending') }}
                            </p>
                        @endif
                        @if ($node->is_ingress && data_get($node->metadata, 'ingress_tls_enabled') === true)
                            <p class="mt-1 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                HTTPS{{ $cluster->ingress_tls_issuer_node_id === $node->id ? ' · Certificate issuer' : '' }}
                            </p>
                        @endif
                    </div>
                    <div class="flex justify-end gap-2">
                        @if ($canUpdateCluster)
                            <x-forms.button wire:click="setIngress('{{ $node->uuid }}', {{ $node->is_ingress ? 'false' : 'true' }})"
                                wire:loading.attr="disabled" wire:target="setIngress"
                                title="{{ $node->is_ingress ? 'Stop serving public traffic on this server.' : 'Serve public traffic for cluster applications on ports 80 and 443 of this server.' }}">
                                {{ $node->is_ingress ? 'Turn off ingress' : 'Turn on ingress' }}
                            </x-forms.button>
                            <x-forms.button wire:click="setBuilder('{{ $node->uuid }}', {{ $node->is_builder ? 'false' : 'true' }})"
                                wire:loading.attr="disabled" wire:target="setBuilder"
                                title="{{ $node->is_builder ? 'Stop building images on this server and remove its build cache.' : 'Build images of Git applications on this server.' }}">
                                {{ $node->is_builder ? 'Turn off builds' : 'Turn on builds' }}
                            </x-forms.button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-application.settings-section>
