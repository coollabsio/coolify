<div>
    <x-slot:title>
        Sentinel Logs | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="sentinel" activeSubMenu="logs" />
        <div class="application-settings-form w-full">
            <x-application.settings-section title="Sentinel logs"
                helper="Recent output of Sentinel: the Sentinel container, or the sentinel.service journal when Sentinel runs on the host."
                flush class="logs-settings-section">
                @if ($server->isSentinelEnabled())
                    @php
                        $sentinelStatus = $server->sentinelStatus();
                        [$sentinelStatusLabel, $sentinelStatusType] = match ($sentinelStatus) {
                            'waiting' => ['Waiting for first report', 'neutral'],
                            'in_sync' => ['In sync', 'success'],
                            default => ['Out of sync', 'warning'],
                        };
                    @endphp
                    <x-slot:actions>
                        <x-status-badge :status="$sentinelStatusLabel" :type="$sentinelStatusType"
                            class="logs-section-status-badge" />
                    </x-slot:actions>
                    @if ($this->isHostRuntime())
                        @can('update', $server)
                            <div class="settings-log-panel flex flex-col gap-2" wire:init="loadJournalLogs">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-sm">Last {{ \App\Livewire\Server\Sentinel\Logs::JOURNAL_LINES }} lines of
                                        <code>journalctl -u sentinel.service</code></span>
                                    <x-forms.button wire:click="loadJournalLogs">
                                        <x-reicon name="refresh" class="size-3.5" />
                                        Refresh
                                    </x-forms.button>
                                </div>
                                <pre class="max-h-[70vh] overflow-auto whitespace-pre-wrap break-all rounded-sm bg-neutral-100 p-3 font-mono text-xs dark:bg-coolgray-100"
                                    wire:loading.class="opacity-50" wire:target="loadJournalLogs">{{ $journalLogs ?? 'Loading…' }}</pre>
                            </div>
                        @else
                            <x-empty size="sm" title="No permission"
                                description="Only users who can edit this server can read the Sentinel logs."
                                icon-name="dashboard" />
                        @endif
                    @else
                        <div class="settings-log-panel">
                            <livewire:project.shared.get-logs :server="$server" container="coolify-sentinel"
                                displayName="Sentinel" :collapsible="false" />
                        </div>
                    @endif
                @else
                    <x-empty size="sm" title="Sentinel is unavailable"
                        description="Sentinel does not run on build or Swarm servers."
                        icon-name="dashboard" />
                @endif
            </x-application.settings-section>
        </div>
    </div>
</div>
