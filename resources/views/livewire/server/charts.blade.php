<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Metrics | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="metrics" />

        <div class="application-settings-form flex w-full flex-col gap-6"
            @if ($server->isMetricsEnabled()) x-init="$wire.loadData()" @endif>
            @if ($server->isMetricsEnabled())
                @if ($this->pollSeconds())
                    <div wire:key="metrics-poll-{{ $this->pollSeconds() }}" wire:poll.{{ $this->pollSeconds() }}s="pollData" class="hidden"></div>
                @endif
                <form wire:submit.prevent="saveMetricsSettings" class="contents">
                    <x-unsaved-bar action="saveMetricsSettings"
                        targets="sentinelMetricsRefreshRateSeconds,sentinelMetricsHistoryDays,sentinelPushIntervalSeconds" />

                    <x-application.settings-section id="server-metrics-overview-section" title="Metrics"
                        helper="Inspect recent CPU and memory usage reported by Sentinel.">
                        <x-slot:actions>
                            <div class="flex items-center gap-2">
                                <x-live-toggle :active="$live" storageKey="server-metrics-live" />
                                <x-forms.button canGate="update" :canResource="$server" wire:click="toggleMetrics">
                                    Disable metrics
                                </x-forms.button>
                            </div>
                        </x-slot:actions>

                        <div class="grid gap-4 lg:grid-cols-3">
                            <x-forms.input canGate="update" :canResource="$server" type="number" min="1"
                                id="sentinelMetricsRefreshRateSeconds" label="Collection rate" required
                                helper="Seconds between metric samples." />
                            <x-forms.input canGate="update" :canResource="$server" type="number" min="1"
                                id="sentinelMetricsHistoryDays" label="History retention" required
                                helper="Days of CPU and memory history to retain." />
                            <x-forms.input canGate="update" :canResource="$server" type="number" min="10"
                                id="sentinelPushIntervalSeconds" label="Push interval" required
                                helper="Seconds between health reports sent to Coolify." />
                        </div>

                        <div class="mt-4 max-w-xs">
                            <x-forms.listbox id="interval" label="Time range" onChange="setInterval" :options="[
                                ['value' => 5, 'label' => 'Last 5 minutes'],
                                ['value' => 10, 'label' => 'Last 10 minutes'],
                                ['value' => 30, 'label' => 'Last 30 minutes'],
                                ['value' => 60, 'label' => 'Last hour'],
                                ['value' => 720, 'label' => 'Last 12 hours'],
                                ['value' => 10080, 'label' => 'Last week'],
                                ['value' => 43200, 'label' => 'Last 30 days'],
                            ]" />
                        </div>
                        <p class="mt-3 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                            Ranges up to one hour refresh live every five seconds. Longer ranges load once; turn on live refresh to update them every minute.
                        </p>
                    </x-application.settings-section>
                </form>

                @include('livewire.server.partials.metrics-charts')
            @elseif ($server->isSentinelEnabled())
                <x-application.settings-section id="server-metrics-overview-section" title="Metrics"
                    helper="Inspect recent CPU and memory usage reported by Sentinel.">
                    <x-empty size="sm" title="Metrics are disabled"
                        description="Enable metrics to begin collecting CPU and memory history for this server."
                        icon-name="dashboard">
                        <x-slot:contents>
                            <x-forms.button canGate="update" :canResource="$server" isHighlighted
                                wire:click="toggleMetrics">
                                Enable metrics
                            </x-forms.button>
                        </x-slot:contents>
                    </x-empty>
                </x-application.settings-section>
            @else
                <x-application.settings-section id="server-metrics-overview-section" title="Metrics"
                    helper="Inspect recent CPU and memory usage reported by Sentinel.">
                    <x-empty size="sm" title="Metrics unavailable"
                        description="Sentinel metrics are unavailable on build and Swarm servers."
                        icon-name="dashboard">
                        <x-slot:contents>
                            <a class="button"
                                href="{{ route('server.sentinel', ['server_uuid' => $server->uuid]) }}"
                                {{ wireNavigate() }}>
                                View Sentinel
                                <x-external-link />
                            </a>
                        </x-slot:contents>
                    </x-empty>
                </x-application.settings-section>
            @endif

        </div>
    </div>
</div>
