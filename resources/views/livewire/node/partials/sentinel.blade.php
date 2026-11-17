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
    $sentinelReconnectTimeout = Carbon\CarbonInterval::seconds(App\Actions\Node\UpgradeSentinel::reconnectTimeoutSeconds())->cascade()->forHumans();
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
        'Running version' => $sentinelVersion ?? 'Unknown',
    ];
@endphp

<x-application.settings-section id="node-sentinel-connection-section" title="Sentinel"
    helper="Sentinel runs on the server as a systemd service and keeps a secure control connection to Coolify.">
    <x-slot:actions>
        <x-table.dropdown panel-class="w-64!" role="menu">
            <x-slot:trigger>
                <button type="button" id="node-sentinel-actions" class="button gap-1.5" aria-haspopup="menu"
                    :aria-expanded="open">
                    <x-loading-on-button wire:loading
                        wire:target="refreshFluxConnection,testFluxConnection,upgradeSentinel,restartSentinel,installSentinel" />
                    Actions
                    @if ($sentinelUpgradeAvailable && ! $sentinelUpgradeActive)
                        <span class="size-1.5 rounded-full bg-warning" title="Upgrade available"></span>
                    @endif
                    <x-reicon name="chevron-down" class="size-3 transition-transform" ::class="open && 'rotate-180'" />
                </button>
            </x-slot:trigger>
            <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                wire:click="refreshFluxConnection" x-on:click="close()">
                <x-reicon name="refresh" class="size-3.5 shrink-0 opacity-70" />
                Refresh status
            </button>
            @can('manageSentinel', $node)
                <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                    wire:click="testFluxConnection" x-on:click="close()">
                    <x-reicon name="link" class="size-3.5 shrink-0 opacity-70" />
                    Test connection
                </button>
                <div class="my-1 border-t border-neutral-200 dark:border-white/[0.08]" role="separator"></div>
                @if ($sentinelUpgradeAvailable && ! $sentinelUpgradeActive)
                    <x-modal-confirmation title="Upgrade Sentinel?" submitAction="upgradeSentinel" buttonFullWidth :actions="[
                        'Sentinel ' . $latestSentinelVersion . ' is installed on this server over SSH and the service restarts.',
                        'The control connection drops briefly while Sentinel restarts.',
                        'If Sentinel does not reconnect within ' . $sentinelReconnectTimeout . ', the previous version is restored over SSH.',
                    ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Upgrade Sentinel">
                        <x-slot:content>
                            <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                                x-on:click="close()">
                                <x-reicon name="server-update" class="size-3.5 shrink-0 opacity-70" />
                                Upgrade to {{ $latestSentinelVersion }}
                            </button>
                        </x-slot:content>
                    </x-modal-confirmation>
                @endif
                <x-modal-confirmation title="Restart Sentinel?" submitAction="restartSentinel" buttonFullWidth
                    :actions="['Sentinel restarts on this server.']" :confirmWithText="false"
                    :confirmWithPassword="false" warningMessage="The control connection drops briefly."
                    step2ButtonText="Restart Sentinel">
                    <x-slot:content>
                        <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                            x-on:click="close()">
                            <x-reicon name="restart" class="size-3.5 shrink-0 opacity-70" />
                            Restart Sentinel
                        </button>
                    </x-slot:content>
                </x-modal-confirmation>
                @if (! $isConnected || $sentinelVersion === null)
                    <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                        wire:click="installSentinel" x-on:click="close()" title="Install Sentinel again over SSH to repair it">
                        <x-reicon name="bandage" class="size-3.5 shrink-0 opacity-70" />
                        Reinstall
                    </button>
                @endif
            @endcan
        </x-table.dropdown>
    </x-slot:actions>

    <div @if ($sentinelUpgradeActive) wire:poll.5s="pollSentinelUpgrade" @elseif ($fluxConnectionPolls > 0) wire:poll.3s="pollFluxConnection" @endif class="flex flex-col gap-5">
        <dl class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Connection</dt>
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
            <div class="min-w-0">
                <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Latest version</dt>
                <dd class="mt-1 flex min-w-0 flex-wrap items-center gap-2">
                    <span class="truncate text-sm font-medium text-neutral-950 dark:text-fg">{{ $latestSentinelVersion ?? 'Unavailable' }}</span>
                    @if ($sentinelUpgradeActive)
                        <x-status-badge :status="'Upgrade '.strtolower($sentinelUpgradeStatusLabel)" type="warning" />
                    @elseif ($sentinelUpgradeAvailable)
                        <x-status-badge status="Upgrade available" type="warning" />
                    @elseif ($latestSentinelVersion !== null && $sentinelVersion !== null)
                        <x-status-badge status="Up to date" type="success" />
                    @endif
                </dd>
            </div>
        </dl>

        @can('manageSentinel', $node)
            @if ($sentinelUpgrade !== null)
                <div class="flex flex-wrap items-center gap-2 text-[12px] text-neutral-600 dark:text-fg-dim">
                    <span>Last upgrade to {{ data_get($sentinelUpgrade, 'version') ?? 'unknown' }}</span>
                    <x-status-badge :status="$sentinelUpgradeStatusLabel" :type="$sentinelUpgradeStatusType" />
                    @if (filled(data_get($sentinelUpgrade, 'error')))
                        <p class="w-full text-red-600 dark:text-red-400">{{ data_get($sentinelUpgrade, 'error') }}</p>
                    @endif
                </div>
            @endif
        @endcan
    </div>
</x-application.settings-section>

@can('manageSentinel', $node)
    <x-application.settings-section id="node-sentinel-troubleshooting-section" title="Troubleshooting"
        helper="Use these actions when the server is not ready or Sentinel cannot connect.">
        <div class="flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.07]">
            <div class="flex flex-wrap items-center justify-between gap-3 pb-4">
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-black dark:text-fg">Repair trust</p>
                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                        Reinstall the Coolify certificate authority on the server when the secure connection is rejected.
                    </p>
                </div>
                <x-forms.button wire:click="repairFluxTrust">Repair trust</x-forms.button>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 py-4">
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-black dark:text-fg">Rotate token</p>
                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                        Issue a new Sentinel token and reinstall Sentinel with it. Use this if the token may have leaked.
                    </p>
                </div>
                <x-modal-confirmation title="Rotate Sentinel token?" buttonTitle="Rotate token"
                    submitAction="rotateSentinelToken" :actions="[
                        'A new token is issued and Sentinel is reinstalled on this server over SSH.',
                        'The previous token stops working immediately.',
                        'If the server cannot be reached, the previous token is kept.',
                    ]" :confirmWithText="false" :confirmWithPassword="false"
                    warningMessage="The control connection drops briefly while Sentinel restarts."
                    step2ButtonText="Rotate token" />
            </div>
            @if (auth()->user()?->can('update', instanceSettings()))
                <div class="flex flex-wrap items-center justify-between gap-3 py-4">
                    <div class="min-w-0">
                        <p class="text-[13px] font-medium text-black dark:text-fg">Renew certificate</p>
                        <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                            Instance admins only. Renews the instance-wide Flux TLS certificate used by every cluster server and restarts Flux.
                        </p>
                        @php $fluxCertificateValidUntil = $this->fluxCertificateValidUntil; @endphp
                        <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                            @if ($fluxCertificateValidUntil !== null)
                                Expires <span class="font-medium text-neutral-700 dark:text-fg" title="{{ $fluxCertificateValidUntil->toIso8601String() }}">{{ $fluxCertificateValidUntil->toFormattedDateString() }}</span>
                                ({{ $fluxCertificateValidUntil->diffForHumans() }}).
                            @else
                                No active certificate.
                            @endif
                            Coolify renews it automatically {{ config('constants.flux.renew_before_days') }} days before it expires.
                        </p>
                    </div>
                    <x-modal-confirmation title="Renew Flux certificate?" buttonTitle="Renew certificate"
                        submitAction="renewFluxCertificate" :actions="[
                            'Coolify issues a new Flux TLS certificate from the current certificate authority.',
                            'Flux restarts, and every cluster server of this instance reconnects.',
                            'If Flux does not serve the new certificate, the previous certificate is restored.',
                        ]" :confirmWithText="false" :confirmWithPassword="false"
                        warningMessage="Cluster servers of all teams lose the control connection briefly."
                        step2ButtonText="Renew certificate" />
                </div>
            @endif
            <details class="group pt-4">
                <summary class="flex cursor-pointer list-none items-center gap-1.5 text-[12px] font-medium text-neutral-600 dark:text-fg-dim [&::-webkit-details-marker]:hidden">
                    <x-reicon name="chevron-down" class="size-3 -rotate-90 transition-transform group-open:rotate-0" />
                    Connection details
                </summary>
            <dl class="mt-3 grid gap-3 text-[12px] sm:grid-cols-2">
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
                        @if (auth()->user()?->can('update', instanceSettings()))
                            <a href="{{ route('settings.node-trust') }}" {{ wireNavigate() }}
                                class="ml-1 font-sans underline">CA rotation</a>
                        @endif
                    </dd>
                    @if (filled($node->flux_trust_bundle_error))
                        <dd class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $node->flux_trust_bundle_error }}</dd>
                    @endif
                </div>
            </dl>
            </details>
        </div>
    </x-application.settings-section>
@endcan
