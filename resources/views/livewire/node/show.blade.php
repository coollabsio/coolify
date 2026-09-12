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
            <div><span class="text-neutral-500 dark:text-fg-dim">Address</span><p class="font-mono text-xs">{{ $node->user }}@{{ $node->ip }}:{{ $node->port }}</p></div>
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
</div>
