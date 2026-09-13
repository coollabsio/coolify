<div class="application-settings-form w-full">
    <x-slot:title>{{ $cluster->name }} | Coolify</x-slot>
    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div><h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">{{ $cluster->name }}</h1><p class="mt-1 text-sm text-neutral-500 dark:text-fg-dim">Private full-mesh network for Nodes.</p></div>
        <div class="flex flex-wrap gap-2"><x-status-badge :status="str($cluster->network_status)->title()" type="neutral" /><x-status-badge label="Revision {{ $cluster->desired_revision }}" /></div>
    </div>
    <div class="flex flex-col gap-6">
        <x-application.settings-section title="Settings" helper="The CIDR can change only before network activation.">
            <form wire:submit="saveSettings" class="flex flex-col gap-4">
                <x-forms.input wire:model="name" label="Name" required /><x-forms.input wire:model="description" label="Description" />
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3"><x-forms.input wire:model="cidr" label="Private CIDR" required /><x-forms.input wire:model="wireguardInterface" label="WireGuard interface" required /><x-forms.input wire:model="wireguardPort" type="number" label="UDP port" required /></div>
                <div class="flex flex-wrap gap-2"><x-forms.button type="submit">Save settings</x-forms.button></div>
            </form>
        </x-application.settings-section>
        <x-application.settings-section title="Nodes" helper="The first usable address is reserved for cluster infrastructure.">
            @can('update', $cluster)
                <form wire:submit="assignNode" class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end"><x-forms.select wire:model="nodeUuid" label="Unassigned Node"><option value="">Select a Node</option>@foreach ($availableNodes as $node)<option value="{{ $node->uuid }}">{{ $node->name }}</option>@endforeach</x-forms.select><x-forms.button type="submit">Assign Node</x-forms.button></form>
            @endcan
            <div class="flex flex-col gap-2">@forelse ($nodes as $node)<div wire:key="cluster-node-{{ $node->uuid }}" class="flex flex-col gap-3 rounded-xl border border-neutral-200 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.08]"><div><a href="{{ route('node.show', $node->uuid) }}" class="font-medium">{{ $node->name }}</a><p class="font-mono text-xs text-neutral-500 dark:text-fg-dim">{{ $node->wireguard_ip }}/32</p></div><div class="flex flex-wrap items-center gap-2"><x-status-badge :status="$node->network_applied_revision === $cluster->desired_revision ? 'Applied' : 'Pending'" :type="$node->network_applied_revision === $cluster->desired_revision ? 'success' : 'warning'" />@can('update', $cluster)<x-forms.button wire:click="removeNode('{{ $node->uuid }}')">Remove</x-forms.button>@endcan</div></div>@empty<x-empty size="sm" title="No Nodes" description="Assign a Node to start this network." icon-name="servers" />@endforelse</div>
        </x-application.settings-section>
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-application.settings-section title="Network" helper="Reconciliation stages and validates each host change before it becomes active.">
                @can('update', $cluster)
                    <x-slot:actions>
                        <div class="flex flex-wrap gap-2">
                            <x-forms.button wire:click="reconcileNetwork" wire:loading.attr="disabled" wire:target="reconcileNetwork">Reconcile network</x-forms.button>
                            <x-forms.button wire:click="repairNetwork" wire:confirm="Restore the last known good network state on every Node over SSH?" wire:loading.attr="disabled" wire:target="repairNetwork">Repair over SSH</x-forms.button>
                        </div>
                    </x-slot:actions>
                @endcan
                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-neutral-500 dark:text-fg-dim">Interface</dt><dd class="font-mono">{{ $cluster->wireguard_interface }}</dd></div>
                    <div><dt class="text-neutral-500 dark:text-fg-dim">Listen port</dt><dd>UDP {{ $cluster->wireguard_port }}</dd></div>
                    <div><dt class="text-neutral-500 dark:text-fg-dim">Desired revision</dt><dd>{{ $cluster->desired_revision }}</dd></div>
                    <div><dt class="text-neutral-500 dark:text-fg-dim">Last reconciled</dt><dd>{{ $cluster->last_reconciled_at?->diffForHumans() ?? 'Never' }}</dd></div>
                </dl>
            </x-application.settings-section>
            <x-application.settings-section title="Discovery" helper="Corrosion state is observed on each Node.">
                <div class="flex flex-col gap-2">
                    @forelse ($nodes as $node)
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span>{{ $node->name }} <span class="text-xs text-neutral-500 dark:text-fg-dim">· {{ data_get($node->metadata, 'corrosion_endpoint_count', 0) }} endpoints</span></span>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @if (data_get($node->metadata, 'corrosion_last_convergence_unix_seconds'))
                                    <span class="text-xs text-neutral-500 dark:text-fg-dim">{{ now()->setTimestamp((int) data_get($node->metadata, 'corrosion_last_convergence_unix_seconds'))->diffForHumans() }}</span>
                                @endif
                                <x-status-badge :status="str($node->corrosion_status ?? 'Pending')->title()" :type="$node->corrosion_status === 'converged' ? 'success' : 'warning'" />
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-neutral-500 dark:text-fg-dim">No discovery members.</p>
                    @endforelse
                </div>
            </x-application.settings-section>
        </div>
        <x-application.settings-section title="Firewall" helper="Workloads cannot connect to other workloads by default. Outbound internet access stays available.">
            @can('update', $cluster)
                <form wire:submit="addFirewallRule" class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-4 md:items-end">
                    <x-forms.select wire:model="firewallSourceUuid" label="Source workload" required>
                        <option value="">Select a workload</option>
                        @foreach ($workloads as $workload)<option value="{{ $workload->uuid }}">{{ $workload->name }}</option>@endforeach
                    </x-forms.select>
                    <x-forms.select wire:model="firewallDestinationUuid" label="Destination workload" required>
                        <option value="">Select a workload</option>
                        @foreach ($workloads as $workload)<option value="{{ $workload->uuid }}">{{ $workload->name }}</option>@endforeach
                    </x-forms.select>
                    <div class="grid grid-cols-2 gap-3">
                        <x-forms.select wire:model="firewallProtocol" label="Protocol" required><option value="tcp">TCP</option><option value="udp">UDP</option></x-forms.select>
                        <x-forms.input wire:model="firewallPort" type="number" min="1" max="65535" label="Port" required />
                    </div>
                    <x-forms.button type="submit">Allow traffic</x-forms.button>
                </form>
            @endcan
            <div class="flex flex-col gap-2">
                @forelse ($firewallRules as $rule)
                    <div wire:key="firewall-rule-{{ $rule->uuid }}" class="flex flex-col gap-2 rounded-xl border border-neutral-200 p-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.08]">
                        <div><p class="text-sm font-medium">{{ $rule->sourceWorkload->name }} → {{ $rule->destinationWorkload->name }}</p><p class="font-mono text-xs text-neutral-500 uppercase dark:text-fg-dim">{{ $rule->protocol }} / {{ $rule->port }}</p></div>
                        @can('update', $cluster)<x-forms.button wire:click="removeFirewallRule('{{ $rule->uuid }}')" wire:confirm="Remove this firewall rule?">Remove</x-forms.button>@endcan
                    </div>
                @empty
                    <x-empty size="sm" title="No allow rules" description="East-west workload traffic is blocked." icon-name="servers" />
                @endforelse
            </div>
        </x-application.settings-section>
        <x-application.settings-section title="Operations" helper="Network changes use durable, typed Flux commands.">
            <div class="flex flex-col gap-2">
                @forelse ($operations as $operation)
                    <div wire:key="cluster-operation-{{ $operation->uuid }}" class="flex flex-col gap-2 rounded-xl border border-neutral-200 p-3 sm:flex-row sm:items-center sm:justify-between dark:border-white/[0.08]">
                        <div class="min-w-0"><p class="truncate text-sm font-medium">{{ $operation->command_type }}</p><p class="text-xs text-neutral-500 dark:text-fg-dim">{{ $operation->node->name }} · {{ $operation->created_at->diffForHumans() }}</p></div>
                        <x-status-badge :status="str($operation->status->value)->title()" :type="$operation->status->value === 'succeeded' ? 'success' : ($operation->status->value === 'failed' ? 'error' : 'warning')" />
                    </div>
                @empty
                    <x-empty size="sm" title="No operations" description="Reconcile the network to create durable operations." icon-name="servers" />
                @endforelse
            </div>
        </x-application.settings-section>
        @can('delete', $cluster)<x-application.settings-section title="Danger zone"><div class="flex flex-wrap"><x-forms.button wire:click="deleteCluster" isError>Delete cluster</x-forms.button></div></x-application.settings-section>@endcan
    </div>
</div>
