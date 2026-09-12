<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <x-slot:title>{{ $node->name }} | Node | Coolify</x-slot>

    <div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1>Node</h1>
                <p class="mt-1 text-sm text-neutral-500 dark:text-fg-dim">{{ $node->name }} · {{ $node->role->value }}</p>
            </div>
            <x-status-badge :status="$node->is_usable ? 'Ready' : 'Not ready'" :type="$node->is_usable ? 'success' : 'warning'" />
        </div>
    </div>

    <x-application.settings-section title="Host Sentinel" helper="Install and manage Sentinel as a systemd service on this node.">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-forms.button wire:click="installSentinel" wire:loading.attr="disabled">Install or update</x-forms.button>
                <x-forms.button wire:click="restartSentinel" wire:loading.attr="disabled">Restart</x-forms.button>
                <x-forms.button wire:click="validateNode" wire:loading.attr="disabled">Validate Podman</x-forms.button>
            </div>
        </x-slot:actions>
        <div class="grid gap-4 text-sm sm:grid-cols-2">
            <div><span class="text-neutral-500 dark:text-fg-dim">Address</span><p class="font-mono text-xs">{{ $node->user.'@'.$node->ip.':'.$node->port }}</p></div>
            <div><span class="text-neutral-500 dark:text-fg-dim">Coolify endpoint</span><p class="break-all font-mono text-xs">{{ $node->sentinel_url }}</p></div>
        </div>
        @if ($node->validation_logs)
            <x-callout type="warning" title="Validation result">{{ $node->validation_logs }}</x-callout>
        @endif
    </x-application.settings-section>

    <x-application.settings-section title="Flux control channel" helper="Direct TLS control connection between Sentinel and Coolify Flux.">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-forms.button wire:click="testFluxConnection" wire:loading.attr="disabled">Test connection</x-forms.button>
                <x-forms.button wire:click="refreshFluxConnection" wire:loading.attr="disabled">Refresh state</x-forms.button>
                <x-forms.button wire:click="renewFluxCertificate" wire:loading.attr="disabled">Renew certificate</x-forms.button>
                <x-forms.button wire:click="repairFluxTrust" wire:loading.attr="disabled">Repair trust</x-forms.button>
            </div>
        </x-slot:actions>
        @if ($fluxConnection)
            <div class="grid gap-4 text-sm sm:grid-cols-2">
                <div><span class="text-neutral-500 dark:text-fg-dim">Status</span><p class="font-medium">{{ data_get($fluxConnection, 'status') }}</p></div>
                <div><span class="text-neutral-500 dark:text-fg-dim">Transport</span><p class="font-medium">{{ strtoupper(data_get($fluxConnection, 'transport', 'unknown')) }}</p></div>
                <div><span class="text-neutral-500 dark:text-fg-dim">Endpoint</span><p class="break-all font-mono text-xs">{{ data_get($fluxConnection, 'endpoint') }}</p></div>
                <div><span class="text-neutral-500 dark:text-fg-dim">Last heartbeat</span><p>{{ data_get($fluxConnection, 'last_heartbeat_at', 'Waiting for heartbeat') }}</p></div>
            </div>
        @else
            <x-status-badge status="Disconnected" type="warning" />
        @endif
    </x-application.settings-section>

    <x-application.settings-section title="System information" helper="System data that Sentinel reports through Flux.">
        <x-slot:actions><x-forms.button wire:click="refreshInformation" wire:loading.attr="disabled">Refresh information</x-forms.button></x-slot:actions>
        <div class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            @foreach (['hostname' => 'Hostname', 'os' => 'Operating system', 'kernel' => 'Kernel', 'arch' => 'Architecture', 'cpus' => 'CPU count', 'container_runtime' => 'Runtime'] as $key => $label)
                <div><span class="text-neutral-500 dark:text-fg-dim">{{ $label }}</span><p>{{ data_get($node->metadata, $key, 'Unknown') }}</p></div>
            @endforeach
        </div>
    </x-application.settings-section>

    <x-application.settings-section title="Containers" helper="Read-only Podman inventory reported by Sentinel through Flux.">
        <x-slot:actions>
            <x-forms.button wire:click="refreshContainers" wire:loading.attr="disabled" wire:target="refreshContainers">
                <span wire:loading.remove wire:target="refreshContainers">Refresh containers</span>
                <span wire:loading wire:target="refreshContainers">Refreshing...</span>
            </x-forms.button>
        </x-slot:actions>
        @if ($node->containers->isEmpty())
            <x-empty size="sm" title="No containers found" description="Refresh the inventory to read containers from Podman." icon-name="servers" />
        @else
            <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-white/[0.08]">
                <div class="grid min-w-[680px] grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)_7rem_8rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                    <div>Container</div>
                    <div>Image</div>
                    <div>State</div>
                    <div>Ownership</div>
                </div>
                @foreach ($node->containers->sortBy('name') as $container)
                    <div wire:key="node-container-{{ $container->uuid }}" class="grid min-h-14 min-w-[680px] grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)_7rem_8rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] last:border-b-0 dark:border-white/[0.07]">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-black dark:text-fg">{{ $container->name }}</p>
                            <p class="truncate font-mono text-[10px] text-neutral-500 dark:text-fg-faint">{{ str($container->runtime_id)->limit(20) }}</p>
                        </div>
                        <p class="truncate font-mono text-[11px] text-neutral-600 dark:text-fg-dim">{{ $container->image }}</p>
                        <div>
                            <x-status-badge :status="str($container->state)->title()" :type="$container->state === 'running' ? 'success' : 'warning'" />
                        </div>
                        <div>
                            <x-status-badge :status="str($container->management_state->value)->title()" :type="match ($container->management_state->value) {
                                'managed' => 'success',
                                'unrecognized' => 'warning',
                                default => 'neutral',
                            }" />
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-application.settings-section>
</div>
