@php
    $isConnected = data_get($fluxConnection, 'status') === 'connected';
    $lastHeartbeat = data_get($fluxConnection, 'last_heartbeat_at');
    try {
        $lastHeartbeatLabel = is_string($lastHeartbeat) ? \Illuminate\Support\Carbon::parse($lastHeartbeat)->diffForHumans() : null;
    } catch (\Throwable) {
        $lastHeartbeatLabel = $lastHeartbeat;
    }
    $sentinelVersion = $node->runningSentinelVersion();
    $latestSentinelVersion = data_get($sentinelRelease, 'version');
    $sentinelUpgradeAvailable = $node->needsSentinelUpgrade($sentinelRelease);
    $sentinelUpgradeStatus = data_get($sentinelUpgrade, 'status');
    $sentinelUpgradeActive = in_array($sentinelUpgradeStatus, ['queued', 'dispatched', 'running', 'verifying', 'uncertain'], true);
    $sentinelUpgradeStatusLabel = match ($sentinelUpgradeStatus) {
        'queued', 'dispatched' => 'Queued',
        'running' => 'Installing',
        'verifying' => 'Waiting for reconnect',
        'succeeded' => 'Succeeded',
        'failed', 'timed_out' => 'Failed',
        'cancelled' => 'Cancelled',
        'uncertain' => 'Uncertain',
        default => null,
    };
    $sentinelUpgradeStatusType = match ($sentinelUpgradeStatus) {
        'succeeded' => 'success',
        'failed', 'timed_out' => 'error',
        'cancelled' => 'neutral',
        default => 'warning',
    };
    $connectionDetails = [
        'Last heartbeat' => $lastHeartbeatLabel ?? 'Waiting for heartbeat',
        'Transport' => $fluxConnection ? strtoupper(data_get($fluxConnection, 'transport', 'unknown')) : 'Not connected',
        'Sentinel version' => $sentinelVersion ?? 'Unknown',
    ];
@endphp

<x-application.settings-section id="node-sentinel-connection-section" title="Connection"
    helper="Sentinel runs on the server as a systemd service and keeps a secure control connection to Coolify.">
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
    <x-application.settings-section id="node-sentinel-version-section" title="Version"
        helper="Upgrades install the latest Sentinel release over SSH. If Sentinel does not reconnect within 60 seconds, the previous version is restored.">
        <div @if ($sentinelUpgradeActive) wire:poll.5s="pollSentinelUpgrade" @endif
            class="flex flex-wrap items-center justify-between gap-3">
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
                <div class="min-w-0">
                    <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Running version</dt>
                    <dd class="mt-1 truncate text-sm font-medium text-neutral-950 dark:text-fg">{{ $sentinelVersion ?? 'Unknown' }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Latest version</dt>
                    <dd class="mt-1 truncate text-sm font-medium text-neutral-950 dark:text-fg">{{ $latestSentinelVersion ?? 'Unavailable' }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Status</dt>
                    <dd class="mt-1">
                        @if ($sentinelUpgradeActive)
                            <x-status-badge :status="'Upgrade '.strtolower($sentinelUpgradeStatusLabel)" type="warning" />
                        @elseif ($sentinelUpgradeAvailable)
                            <x-status-badge status="Upgrade available" type="warning" />
                        @elseif ($latestSentinelVersion !== null && $sentinelVersion !== null)
                            <x-status-badge status="Up to date" type="success" />
                        @else
                            <x-status-badge status="Unknown" type="neutral" />
                        @endif
                    </dd>
                </div>
            </dl>
            @if ($sentinelUpgradeAvailable && ! $sentinelUpgradeActive)
                <x-modal-confirmation title="Upgrade Sentinel?" buttonTitle="Upgrade Sentinel"
                    submitAction="upgradeSentinel" :actions="[
                        'Sentinel ' . $latestSentinelVersion . ' is installed on this server over SSH and the service restarts.',
                        'The control connection drops briefly while Sentinel restarts.',
                        'If Sentinel does not reconnect within 60 seconds, the previous version is restored.',
                    ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Upgrade Sentinel" />
            @endif
        </div>
        @if ($sentinelUpgrade !== null)
            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-neutral-200 pt-4 text-[12px] text-neutral-600 dark:border-white/[0.07] dark:text-fg-dim">
                <span>Last upgrade to {{ data_get($sentinelUpgrade, 'version') ?? 'unknown' }}</span>
                <x-status-badge :status="$sentinelUpgradeStatusLabel" :type="$sentinelUpgradeStatusType" />
                @if (filled(data_get($sentinelUpgrade, 'error')))
                    <p class="w-full text-red-600 dark:text-red-400">{{ data_get($sentinelUpgrade, 'error') }}</p>
                @endif
            </div>
        @endif
    </x-application.settings-section>

    <x-application.settings-section id="node-sentinel-service-section" title="Sentinel service"
        helper="Install the latest Sentinel release on this server or restart the service.">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-[13px] text-neutral-600 dark:text-fg-dim">
                Updating installs the latest Sentinel release over SSH and restarts the service.
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <x-forms.button wire:click="installSentinel">Update Sentinel</x-forms.button>
                <x-modal-confirmation title="Restart Sentinel?" buttonTitle="Restart" submitAction="restartSentinel"
                    :actions="['Sentinel restarts on this server.']" :confirmWithText="false"
                    :confirmWithPassword="false" warningMessage="The control connection drops briefly."
                    step2ButtonText="Restart Sentinel" />
            </div>
        </div>
    </x-application.settings-section>

    <x-application.settings-section id="node-sentinel-troubleshooting-section" title="Troubleshooting"
        helper="Use these actions when the server is not ready or Sentinel cannot connect.">
        <div class="flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.07]">
            <div class="flex flex-wrap items-center justify-between gap-3 pb-4">
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-black dark:text-fg">Validate server</p>
                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                        Check Podman and the host requirements, then update the server status.
                    </p>
                </div>
                <x-forms.button wire:click="validateNode">Validate server</x-forms.button>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 py-4">
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-black dark:text-fg">Repair trust</p>
                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                        Reinstall the Coolify certificate authority on the server when the secure connection is rejected.
                    </p>
                </div>
                <x-forms.button wire:click="repairFluxTrust">Repair trust</x-forms.button>
            </div>
            @if (isDev() && auth()->user()?->can('update', instanceSettings()))
                <div class="flex flex-wrap items-center justify-between gap-3 py-4">
                    <div class="min-w-0">
                        <p class="text-[13px] font-medium text-black dark:text-fg">Renew certificate</p>
                        <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                            Development only. Renews the instance-wide Flux TLS certificate used by every cluster server.
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
                <div class="min-w-0">
                    <dt class="text-neutral-500 dark:text-fg-faint">Trust bundle</dt>
                    <dd class="mt-0.5 break-all font-mono text-[11px] text-neutral-700 dark:text-fg-dim">
                        {{ $node->flux_trust_bundle_version ?? 'Unknown' }}
                        @if (isInstanceAdmin())
                            <a href="{{ route('settings.node-trust') }}" {{ wireNavigate() }}
                                class="ml-1 font-sans underline">CA rotation</a>
                        @endif
                    </dd>
                    @if (filled($node->flux_trust_bundle_error))
                        <dd class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $node->flux_trust_bundle_error }}</dd>
                    @endif
                </div>
            </dl>
        </div>
    </x-application.settings-section>
@endcan
