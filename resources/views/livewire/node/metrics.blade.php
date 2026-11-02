<div>
    <x-slot:title>{{ $node->name }} > Metrics | Coolify</x-slot>
    <x-node.navbar :node="$node" />
    <div class="node-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-node.sidebar :node="$node" activeMenu="metrics" />
        <div class="application-settings-form flex w-full min-w-0 flex-col gap-6" @if ($node->isMetricsEnabled()) x-init="$wire.loadData()" @endif>
            @if ($node->isMetricsEnabled())
            <div wire:key="node-metrics-enabled" class="contents">
            @if ($this->pollSeconds())
                <div wire:key="metrics-poll-{{ $this->pollSeconds() }}" wire:poll.{{ $this->pollSeconds() }}s="pollData" class="hidden"></div>
            @endif
            <x-application.settings-section title="Metrics" helper="Historical host usage reported by Sentinel.">
                <x-slot:actions>
                    <div class="flex items-center gap-2">
                        <x-live-toggle :active="$live" storageKey="node-metrics-live" />
                        <x-forms.button canGate="update" :canResource="$node" wire:click="toggleMetrics"
                            wire:loading.attr="disabled" wire:target="toggleMetrics">Disable metrics</x-forms.button>
                    </div>
                </x-slot:actions>
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
            @include('livewire.server.partials.metrics-charts')
            </div>
            @else
                <x-application.settings-section title="Metrics" helper="Inspect recent CPU and memory usage reported by Sentinel.">
                    <x-empty size="sm" title="Metrics are disabled"
                        description="Enable metrics to begin collecting CPU and memory history for this server." icon-name="dashboard">
                        <x-slot:contents>
                            <x-forms.button canGate="update" :canResource="$node" isHighlighted wire:click="toggleMetrics"
                                wire:loading.attr="disabled" wire:target="toggleMetrics">Enable metrics</x-forms.button>
                        </x-slot:contents>
                    </x-empty>
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>
