@php
    $canUpdateCluster = auth()->user()->can('update', $cluster);
    $workloadOptions = $workloads->map(fn ($workload) => ['value' => $workload->uuid, 'label' => $workload->name])->values()->all();
    $sourceOptions = [
        ['value' => '__group_nodes', 'label' => 'Servers', 'header' => true],
        ...$nodes->map(fn ($node) => ['value' => 'node:'.$node->uuid, 'label' => $node->name])->all(),
        ['value' => '__group_workloads', 'label' => 'Applications', 'header' => true],
        ...$workloads->map(fn ($workload) => ['value' => 'workload:'.$workload->uuid, 'label' => $workload->name])->all(),
    ];
    $systemRules = [
        'WireGuard' => 'UDP / '.$cluster->wireguard_port,
        'Corrosion gossip' => 'UDP / 8787',
        'Corrosion local API' => 'TCP / 8080',
        'Application DNS' => 'TCP + UDP / 53',
        'Established connections' => 'Stateful',
        'Other mesh traffic' => 'Denied',
    ];
    $ruleGridClasses = 'grid min-w-[480px] grid-cols-[minmax(0,1fr)_8rem_5rem] items-center gap-3 px-4';
@endphp

<x-application.settings-section id="node-cluster-traffic-map-section" title="Traffic map"
    helper="Servers allow only required cluster traffic by default. Applications reject mesh and external connections by default. Outbound internet access stays available.">
    @include('livewire.node-cluster.firewall-canvas')
</x-application.settings-section>

<x-application.settings-section id="node-cluster-workload-rules-section" title="Application rules"
    helper="Allow traffic from a server or application to an application port inside the cluster." flush>
    @if ($canUpdateCluster && $workloads->isNotEmpty())
        <x-slot:actions>
            <x-modal-input title="Add application rule" :wireIgnore="false">
                <x-slot:content>
                    <button type="button" class="button">
                        <x-reicon name="plus" class="size-3.5" />
                        Add rule
                    </button>
                </x-slot:content>
                <form wire:submit="addFirewallRule" class="flex flex-col gap-4">
                    <x-forms.listbox id="firewallSourceUuid" label="Source" placeholder="Select a source" required portal
                        live :options="$sourceOptions" />
                    <div wire:key="firewall-destination-{{ $firewallSourceUuid }}">
                        <x-forms.listbox id="firewallDestinationUuid" label="Destination"
                            placeholder="Select an application" required portal :options="collect($workloadOptions)
                                ->reject(fn (array $option): bool => 'workload:'.$option['value'] === $firewallSourceUuid)
                                ->values()
                                ->all()" />
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-forms.listbox id="firewallProtocol" label="Protocol" required portal live :options="[
                            ['value' => 'tcp', 'label' => 'TCP'],
                            ['value' => 'udp', 'label' => 'UDP'],
                            ['value' => 'icmp', 'label' => 'ICMP'],
                        ]" />
                        @if ($firewallProtocol !== 'icmp')
                            <x-forms.input id="firewallPort" type="number" min="1" max="65535" label="Port" required />
                        @endif
                    </div>
                    <div class="flex justify-end">
                        <x-forms.button type="submit" isHighlighted>Allow traffic</x-forms.button>
                    </div>
                </form>
            </x-modal-input>
        </x-slot:actions>
    @endif

    @if ($firewallRules->isEmpty())
        <div class="p-4">
            <x-empty size="sm" title="No application rules"
                description="Traffic between applications in this cluster is blocked." icon-name="shield-star" />
        </div>
    @else
        <div class="overflow-x-auto">
            <div
                class="{{ $ruleGridClasses }} border-b border-neutral-200 bg-neutral-50 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                <div>Traffic</div>
                <div>Protocol / port</div>
                <div></div>
            </div>
            @foreach ($firewallRules as $rule)
                <div wire:key="firewall-rule-{{ $rule->uuid }}"
                    class="{{ $ruleGridClasses }} min-h-12 border-b border-neutral-200 py-2 text-[12px] last:border-b-0 dark:border-white/[0.07]">
                    <div class="min-w-0 truncate text-[13px] font-medium text-black dark:text-fg">
                        {{ $rule->sourceNode?->name ?? $rule->sourceWorkload?->name }} → {{ $rule->destinationWorkload->name }}
                    </div>
                    <div class="font-mono text-[12px] text-neutral-600 uppercase dark:text-fg-dim">
                        {{ $rule->protocol }}{{ $rule->protocol !== 'icmp' ? ' / '.$rule->port : '' }}
                    </div>
                    <div class="flex justify-end">
                        @if ($canUpdateCluster)
                            <x-modal-confirmation title="Remove application rule?" buttonTitle="Remove"
                                submitAction="removeFirewallRule({{ $rule->uuid }})" :actions="[
                                    'Traffic from '.($rule->sourceNode?->name ?? $rule->sourceWorkload?->name).' to '.$rule->destinationWorkload->name.' on '.strtoupper($rule->protocol).($rule->protocol !== 'icmp' ? ' / '.$rule->port : '').' is blocked again.',
                                ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Remove rule" />
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-application.settings-section>

<x-application.settings-section id="node-cluster-system-rules-section" title="System rules"
    helper="Coolify adds these rules on every server so that the cluster can work. You cannot change them."
    flush>
    <x-slot:actions>
        <span class="table-badge">Managed by Coolify</span>
    </x-slot:actions>
    <div class="overflow-x-auto">
        <div
            class="{{ $ruleGridClasses }} border-b border-neutral-200 bg-neutral-50 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
            <div>Rule</div>
            <div>Traffic</div>
            <div></div>
        </div>
        @foreach ($systemRules as $ruleName => $ruleTraffic)
            <div
                class="{{ $ruleGridClasses }} min-h-12 border-b border-neutral-200 py-2 text-[12px] last:border-b-0 dark:border-white/[0.07]">
                <div class="min-w-0 truncate text-[13px] font-medium text-black dark:text-fg">{{ $ruleName }}</div>
                <div class="font-mono text-[12px] text-neutral-600 uppercase dark:text-fg-dim">{{ $ruleTraffic }}</div>
                <div></div>
            </div>
        @endforeach
    </div>
</x-application.settings-section>
