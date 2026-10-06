<div>
    <x-slot:title>
        Internal DNS | {{ data_get_str($node, 'name')->limit(24) }} | Coolify
    </x-slot>

    <x-node.navbar :node="$node" />

    <div
        class="node-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-node.sidebar :node="$node" activeMenu="internal-dns" />

        <div class="flex w-full min-w-0 flex-col gap-6">
            <x-application.settings-section title="Internal DNS" flush
                helper="Private hostnames that workloads in this cluster use to reach each other. Records are shared by every server in the cluster.">
                <x-slot:actions>
                    <x-forms.button type="button" wire:click="refreshEndpoints" title="Refresh DNS records">
                        <x-reicon name="refresh" class="size-3.5" />
                        Refresh
                    </x-forms.button>
                </x-slot:actions>

                @if ($loadError)
                    <div class="p-4">
                        <x-callout type="warning" title="Internal DNS is unavailable">{{ $loadError }}</x-callout>
                    </div>
                @elseif (empty($endpoints))
                    <div class="p-4">
                        <x-empty size="sm" title="No internal DNS records"
                            description="Deploy a workload to publish its internal hostname." icon-name="network" />
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <div
                            class="grid min-w-[850px] grid-cols-[minmax(15rem,1.8fr)_minmax(8rem,0.8fr)_minmax(10rem,1fr)_7rem_8rem] border-b border-neutral-200 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                            <div>Hostname</div>
                            <div>Address</div>
                            <div>Server</div>
                            <div>Status</div>
                            <div>Expires</div>
                        </div>
                        @foreach ($endpoints as $endpoint)
                            @php
                                $isExpired = data_get($endpoint, 'expires_at', 0) <= now()->timestamp;
                                $health = $isExpired ? 'expired' : data_get($endpoint, 'health', 'unknown');
                                $status = $health === 'unknown' ? data_get($endpoint, 'state', 'unknown') : $health;
                                $ownerNodeIp = data_get($endpoint, 'owner_node_ip');
                                $expiresAt = \Carbon\Carbon::createFromTimestamp(data_get($endpoint, 'expires_at'));
                            @endphp
                            <div wire:key="node-dns-{{ data_get($endpoint, 'hostname') }}-{{ data_get($endpoint, 'container_ip') }}"
                                class="grid min-h-12 min-w-[850px] grid-cols-[minmax(15rem,1.8fr)_minmax(8rem,0.8fr)_minmax(10rem,1fr)_7rem_8rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] last:border-b-0 dark:border-white/[0.07]">
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="truncate font-mono text-[11px] text-black dark:text-fg">
                                        {{ data_get($endpoint, 'hostname') }}
                                    </span>
                                    <x-copy-button :value="data_get($endpoint, 'hostname')" label="Copy internal hostname" />
                                </div>
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="font-mono text-[11px] text-neutral-600 dark:text-fg-dim">
                                        {{ data_get($endpoint, 'container_ip') }}
                                    </span>
                                    <x-copy-button :value="data_get($endpoint, 'container_ip')" label="Copy internal address" />
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-black dark:text-fg">
                                        {{ $nodeNamesByAddress[$ownerNodeIp] ?? 'Unknown server' }}
                                    </p>
                                    <p class="font-mono text-[10px] text-neutral-500 dark:text-fg-faint">{{ $ownerNodeIp }}</p>
                                </div>
                                <div class="justify-self-start">
                                    <x-status-badge :status="str($status)->title()" :type="match ($status) {
                                        'healthy', 'running' => 'success',
                                        'expired', 'unhealthy' => 'error',
                                        default => 'warning',
                                    }" />
                                </div>
                                <span class="text-neutral-500 dark:text-fg-faint" title="{{ $expiresAt->toIso8601String() }}">
                                    {{ $expiresAt->diffForHumans() }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </div>
</div>
