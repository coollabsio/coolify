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
        'Workload DNS' => 'TCP + UDP / 53',
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
                        :options="$sourceOptions" />
                    <x-forms.listbox id="firewallDestinationUuid" label="Destination application"
                        placeholder="Select an application" required portal :options="$workloadOptions" />
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
                            <x-forms.button wire:click="removeFirewallRule('{{ $rule->uuid }}')"
                                wire:confirm="Remove this firewall rule?">
                                Remove
                            </x-forms.button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-application.settings-section>

<x-application.settings-section id="node-cluster-ingress-rules-section" title="Ingress rules"
    helper="Allow a server process, unmanaged container, LAN host, proxy, or public port mapping to reach one application port. Traffic between applications still needs an application rule."
    flush>
    @if ($canUpdateCluster && $workloads->isNotEmpty())
        <x-slot:actions>
            <x-modal-input title="Add ingress rule" :wireIgnore="false">
                <x-slot:content>
                    <button type="button" class="button">
                        <x-reicon name="plus" class="size-3.5" />
                        Add rule
                    </button>
                </x-slot:content>
                <form wire:submit="addIngressRule" class="flex flex-col gap-4">
                    <x-forms.listbox id="ingressDestinationUuid" label="Destination application"
                        placeholder="Select an application" required portal :options="$workloadOptions" />
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-forms.listbox id="ingressProtocol" label="Protocol" required portal :options="[
                            ['value' => 'tcp', 'label' => 'TCP'],
                            ['value' => 'udp', 'label' => 'UDP'],
                        ]" />
                        <x-forms.input id="ingressPort" type="number" min="1" max="65535" label="Port" required />
                    </div>
                    <div class="flex justify-end">
                        <x-forms.button type="submit" isHighlighted>Allow ingress</x-forms.button>
                    </div>
                </form>
            </x-modal-input>
        </x-slot:actions>
    @endif

    @if ($ingressRules->isEmpty())
        <div class="p-4">
            <x-empty size="sm" title="No ingress rules"
                description="Connections from outside the cluster network are blocked." icon-name="shield-star" />
        </div>
    @else
        <div class="overflow-x-auto">
            <div
                class="{{ $ruleGridClasses }} border-b border-neutral-200 bg-neutral-50 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                <div>Destination</div>
                <div>Protocol / port</div>
                <div></div>
            </div>
            @foreach ($ingressRules as $rule)
                <div wire:key="ingress-rule-{{ $rule->uuid }}"
                    class="{{ $ruleGridClasses }} min-h-12 border-b border-neutral-200 py-2 text-[12px] last:border-b-0 dark:border-white/[0.07]">
                    <div class="min-w-0 truncate text-[13px] font-medium text-black dark:text-fg">
                        External sources → {{ $rule->destinationWorkload->name }}
                    </div>
                    <div class="font-mono text-[12px] text-neutral-600 uppercase dark:text-fg-dim">
                        {{ $rule->protocol }} / {{ $rule->port }}
                    </div>
                    <div class="flex justify-end">
                        @if ($canUpdateCluster)
                            <x-forms.button wire:click="removeIngressRule('{{ $rule->uuid }}')"
                                wire:confirm="Remove this ingress rule?">
                                Remove
                            </x-forms.button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-application.settings-section>

<details class="group rounded-lg border border-neutral-200 dark:border-white/[0.08]">
    <summary
        class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-black dark:text-fg">
        <span>System rules</span>
        <span class="flex items-center gap-2 text-xs font-normal text-neutral-500 dark:text-fg-dim">
            Managed by Coolify
            <x-reicon name="chevron-down" class="size-3.5 transition-transform group-open:rotate-180" />
        </span>
    </summary>
    <dl class="grid grid-cols-1 gap-x-6 gap-y-2 border-t border-neutral-200 px-4 py-3 text-[13px] sm:grid-cols-2 dark:border-white/[0.08]">
        @foreach ($systemRules as $ruleName => $ruleTraffic)
            <div class="flex items-center justify-between gap-3">
                <dt class="text-neutral-700 dark:text-fg">{{ $ruleName }}</dt>
                <dd class="font-mono text-xs text-neutral-500 uppercase dark:text-fg-dim">{{ $ruleTraffic }}</dd>
            </div>
        @endforeach
    </dl>
</details>
