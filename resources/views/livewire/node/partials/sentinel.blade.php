@php
    $isConnected = data_get($fluxConnection, 'status') === 'connected';
    $lastHeartbeat = data_get($fluxConnection, 'last_heartbeat_at');
    try {
        $lastHeartbeatLabel = is_string($lastHeartbeat) ? \Illuminate\Support\Carbon::parse($lastHeartbeat)->diffForHumans() : null;
    } catch (\Throwable) {
        $lastHeartbeatLabel = $lastHeartbeat;
    }
    $sentinelVersion = data_get($node->metadata, 'sentinel_version') ?? data_get($fluxConnection, 'sentinel_version');
    $connectionDetails = [
        'Last heartbeat' => $lastHeartbeatLabel ?? 'Waiting for heartbeat',
        'Transport' => $fluxConnection ? strtoupper(data_get($fluxConnection, 'transport', 'unknown')) : 'Not connected',
        'Sentinel version' => $sentinelVersion ?? 'Unknown',
    ];
@endphp

<x-application.settings-section id="node-sentinel-connection-section" title="Connection"
    helper="Sentinel runs on the Node as a systemd service and keeps a secure control connection to Coolify.">
    <x-slot:actions>
        @can('manageSentinel', $node)
            <x-forms.button wire:click="testFluxConnection">Test connection</x-forms.button>
        @endcan
        <x-forms.button type="button" class="size-8! px-0!" wire:click="refreshFluxConnection"
            title="Refresh connection state">
            <x-reicon name="refresh" class="size-3.5" />
        </x-forms.button>
    </x-slot:actions>

    <dl class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Status</dt>
            <dd class="mt-1">
                <x-status-badge :status="$isConnected ? 'Connected' : 'Disconnected'"
                    :type="$isConnected ? 'success' : 'warning'" />
            </dd>
        </div>
        @foreach ($connectionDetails as $detailLabel => $detailValue)
            <div class="min-w-0">
                <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">{{ $detailLabel }}</dt>
                <dd class="mt-1 truncate text-sm font-medium text-neutral-950 dark:text-fg"
                    @if ($detailLabel === 'Last heartbeat' && is_string($lastHeartbeat)) title="{{ $lastHeartbeat }}" @endif>
                    {{ $detailValue }}
                </dd>
            </div>
        @endforeach
    </dl>
</x-application.settings-section>

@can('manageSentinel', $node)
    <x-application.settings-section id="node-sentinel-service-section" title="Sentinel service"
        helper="Install the latest Sentinel release on this Node or restart the service.">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-[13px] text-neutral-600 dark:text-fg-dim">
                Updating installs the latest Sentinel release over SSH and restarts the service.
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <x-forms.button wire:click="installSentinel">Update Sentinel</x-forms.button>
                <x-forms.button wire:click="restartSentinel"
                    wire:confirm="Restart Sentinel on this Node? The control connection drops briefly.">
                    Restart
                </x-forms.button>
            </div>
        </div>
    </x-application.settings-section>

    <x-application.settings-section id="node-sentinel-troubleshooting-section" title="Troubleshooting"
        helper="Use these actions when the Node is not ready or Sentinel cannot connect.">
        <div class="flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.07]">
            <div class="flex flex-wrap items-center justify-between gap-3 pb-4">
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-black dark:text-fg">Validate node</p>
                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                        Check Podman and the host requirements, then update the Node status.
                    </p>
                </div>
                <x-forms.button wire:click="validateNode">Validate node</x-forms.button>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 py-4">
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-black dark:text-fg">Repair trust</p>
                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                        Reinstall the Coolify certificate authority on the Node when the secure connection is rejected.
                    </p>
                </div>
                <x-forms.button wire:click="repairFluxTrust">Repair trust</x-forms.button>
            </div>
            @if (isDev())
                <div class="flex flex-wrap items-center justify-between gap-3 py-4">
                    <div class="min-w-0">
                        <p class="text-[13px] font-medium text-black dark:text-fg">Renew certificate</p>
                        <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                            Development only. Renews the instance-wide Flux TLS certificate used by every Node.
                        </p>
                    </div>
                    <x-forms.button wire:click="renewFluxCertificate">Renew certificate</x-forms.button>
                </div>
            @endif
            <dl class="grid gap-3 pt-4 text-[12px] sm:grid-cols-2">
                <div class="min-w-0">
                    <dt class="text-neutral-500 dark:text-fg-faint">Coolify endpoint</dt>
                    <dd class="mt-0.5 break-all font-mono text-[11px] text-neutral-700 dark:text-fg-dim">
                        {{ $node->sentinel_url ?: 'Not set' }}
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-neutral-500 dark:text-fg-faint">Control endpoint</dt>
                    <dd class="mt-0.5 break-all font-mono text-[11px] text-neutral-700 dark:text-fg-dim">
                        {{ data_get($fluxConnection, 'endpoint') ?? 'Not connected' }}
                    </dd>
                </div>
            </dl>
        </div>
    </x-application.settings-section>
@endcan
