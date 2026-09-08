<div>
    <x-slot:title>
        Sentinel Logs | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="sentinel" />
        <div class="application-settings-form w-full">
            <x-application.settings-section title="Sentinel logs"
                helper="Search, filter, follow, copy, or download recent output from the Sentinel container."
                flush class="logs-settings-section">
                @if ($server->isSentinelEnabled())
                    <x-slot:actions>
                        <x-status-badge :status="$server->isSentinelLive() ? 'In sync' : 'Out of sync'"
                            :type="$server->isSentinelLive() ? 'success' : 'warning'"
                            class="logs-section-status-badge" />
                    </x-slot:actions>
                    <div class="settings-log-panel">
                        <livewire:project.shared.get-logs :server="$server" container="coolify-sentinel"
                            displayName="Sentinel" :collapsible="false" />
                    </div>
                @else
                    <x-slot:actions>
                        <x-forms.button canGate="manageSentinel" :canResource="$server" isHighlighted
                            wire:click="enableSentinel">
                            Enable Sentinel
                        </x-forms.button>
                    </x-slot:actions>
                    <x-empty size="sm" title="Sentinel is disabled"
                        description="Enable Sentinel to view its logs."
                        icon-name="dashboard" />
                @endif
            </x-application.settings-section>
        </div>
    </div>
</div>
