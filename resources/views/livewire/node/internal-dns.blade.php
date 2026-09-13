<div>
    <x-slot:title>Internal DNS | {{ $node->name }} | Coolify</x-slot>

    <x-node.navbar :node="$node" />

    <div class="node-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-node.sidebar :node="$node" activeMenu="internal-dns" />

        <div class="flex w-full min-w-0 flex-col gap-6">
            <x-application.settings-section title="Internal DNS" helper="Discovery records replicated through Corrosion for this Node cluster.">
                <x-slot:actions>
                    <x-forms.button wire:click="refreshEndpoints" wire:loading.attr="disabled" wire:target="refreshEndpoints">
                        <span wire:loading.remove wire:target="refreshEndpoints">Refresh records</span>
                        <span wire:loading wire:target="refreshEndpoints">Refreshing...</span>
                    </x-forms.button>
                </x-slot:actions>

                @if ($loadError)
                    <x-callout type="warning" title="Internal DNS is unavailable">{{ $loadError }}</x-callout>
                @elseif (empty($endpoints))
                    <x-empty size="sm" title="No internal DNS records" description="Deploy a workload to publish its internal hostname." icon-name="network" />
                @else
                    <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-white/[0.08]">
                        <div class="grid min-w-[850px] grid-cols-[minmax(15rem,1.8fr)_minmax(8rem,0.8fr)_minmax(10rem,1fr)_7rem_8rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                            <div>Hostname</div>
                            <div>Address</div>
                            <div>Owner Node</div>
                            <div>Status</div>
                            <div>Expires</div>
                        </div>
                        @foreach ($endpoints as $endpoint)
                            @php($isExpired = data_get($endpoint, 'expires_at', 0) <= now()->timestamp)
                            @php($health = $isExpired ? 'expired' : data_get($endpoint, 'health', 'unknown'))
                            @php($status = $health === 'unknown' ? data_get($endpoint, 'state', 'unknown') : $health)
                            @php($statusLabel = str($status)->title())
                            @php($ownerNodeIp = data_get($endpoint, 'owner_node_ip'))
                            <div wire:key="node-dns-{{ data_get($endpoint, 'hostname') }}-{{ data_get($endpoint, 'container_ip') }}" class="grid min-h-14 min-w-[850px] grid-cols-[minmax(15rem,1.8fr)_minmax(8rem,0.8fr)_minmax(10rem,1fr)_7rem_8rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] last:border-b-0 dark:border-white/[0.07]">
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="truncate font-mono text-[11px] text-black dark:text-fg">{{ data_get($endpoint, 'hostname') }}</span>
                                    <x-copy-button :value="data_get($endpoint, 'hostname')" label="Copy internal hostname" />
                                </div>
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="font-mono text-[11px] text-neutral-600 dark:text-fg-dim">{{ data_get($endpoint, 'container_ip') }}</span>
                                    <x-copy-button :value="data_get($endpoint, 'container_ip')" label="Copy internal address" />
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-black dark:text-fg">{{ $nodeNamesByAddress[$ownerNodeIp] ?? 'Unknown Node' }}</p>
                                    <p class="font-mono text-[10px] text-neutral-500 dark:text-fg-faint">{{ $ownerNodeIp }}</p>
                                </div>
                                <x-status-badge :status="$statusLabel" :type="match ($status) {
                                    'healthy', 'running' => 'success',
                                    'expired', 'unhealthy' => 'error',
                                    default => 'warning',
                                }" />
                                <span class="text-neutral-500 dark:text-fg-faint" title="{{ \Carbon\Carbon::createFromTimestamp(data_get($endpoint, 'expires_at'))->toIso8601String() }}">
                                    {{ \Carbon\Carbon::createFromTimestamp(data_get($endpoint, 'expires_at'))->diffForHumans() }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </div>
</div>
