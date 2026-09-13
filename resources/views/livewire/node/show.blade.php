<div>
    <x-slot:title>{{ $node->name }} | Node | Coolify</x-slot>

    <x-node.navbar :node="$node" />

    <div
        class="node-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-node.sidebar :node="$node" activeMenu="general" />
        <div class="flex w-full min-w-0 flex-col gap-6">
    <x-application.settings-section title="Cluster network" helper="Coolify owns Node membership and desired private network state.">
        @if ($node->cluster)
            <div class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><span class="text-neutral-500 dark:text-fg-dim">Cluster</span><p><a href="{{ route('node-cluster.show', $node->cluster->uuid) }}">{{ $node->cluster->name }}</a></p></div>
                <div><span class="text-neutral-500 dark:text-fg-dim">WireGuard address</span><p class="font-mono text-xs">{{ $node->wireguard_ip }}/32</p></div>
                <div><span class="text-neutral-500 dark:text-fg-dim">Revision</span><p>{{ $node->network_applied_revision ?? 'Pending' }} / {{ $node->cluster->desired_revision }}</p></div>
                <div><span class="text-neutral-500 dark:text-fg-dim">Discovery</span><p>{{ $node->corrosion_status ?? 'Pending' }}</p></div>
            </div>
        @else
            <p class="text-sm text-neutral-500 dark:text-fg-dim">This Node is not assigned to a cluster.</p>
        @endif
    </x-application.settings-section>

    <x-application.settings-section title="Host Sentinel" helper="Install and manage Sentinel as a systemd service on this node.">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-forms.button wire:click="installSentinel" wire:loading.attr="disabled">Install or update</x-forms.button>
                <x-forms.button wire:click="restartSentinel" wire:loading.attr="disabled">Restart</x-forms.button>
                <x-forms.button wire:click="validateNode" wire:loading.attr="disabled">Validate Podman</x-forms.button>
            </div>
        </x-slot:actions>
        <div class="grid gap-4 text-sm sm:grid-cols-2">
            <div><span class="text-neutral-500 dark:text-fg-dim">Address</span><p class="font-mono text-xs">{{ $node->user.'@'.$node->ip.':'.$node->port }}</p></div>
            <div><span class="text-neutral-500 dark:text-fg-dim">Coolify endpoint</span><p class="break-all font-mono text-xs">{{ $node->sentinel_url }}</p></div>
        </div>
        @if ($node->validation_logs)
            <x-callout type="warning" title="Validation result">{{ $node->validation_logs }}</x-callout>
        @endif
    </x-application.settings-section>

    <x-application.settings-section title="Flux control channel" helper="Direct TLS control connection between Sentinel and Coolify Flux.">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-forms.button wire:click="testFluxConnection" wire:loading.attr="disabled">Test connection</x-forms.button>
                <x-forms.button wire:click="refreshFluxConnection" wire:loading.attr="disabled">Refresh state</x-forms.button>
                <x-forms.button wire:click="renewFluxCertificate" wire:loading.attr="disabled">Renew certificate</x-forms.button>
                <x-forms.button wire:click="repairFluxTrust" wire:loading.attr="disabled">Repair trust</x-forms.button>
            </div>
        </x-slot:actions>
        @if ($fluxConnection)
            <div class="grid gap-4 text-sm sm:grid-cols-2">
                <div><span class="text-neutral-500 dark:text-fg-dim">Status</span><p class="font-medium">{{ data_get($fluxConnection, 'status') }}</p></div>
                <div><span class="text-neutral-500 dark:text-fg-dim">Transport</span><p class="font-medium">{{ strtoupper(data_get($fluxConnection, 'transport', 'unknown')) }}</p></div>
                <div><span class="text-neutral-500 dark:text-fg-dim">Endpoint</span><p class="break-all font-mono text-xs">{{ data_get($fluxConnection, 'endpoint') }}</p></div>
                <div><span class="text-neutral-500 dark:text-fg-dim">Last heartbeat</span><p>{{ data_get($fluxConnection, 'last_heartbeat_at', 'Waiting for heartbeat') }}</p></div>
            </div>
        @else
            <x-status-badge status="Disconnected" type="warning" />
        @endif
    </x-application.settings-section>

    <x-application.settings-section title="System information" helper="System data that Sentinel reports through Flux.">
        <x-slot:actions><x-forms.button wire:click="refreshInformation" wire:loading.attr="disabled">Refresh information</x-forms.button></x-slot:actions>
        <div class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            @foreach (['hostname' => 'Hostname', 'os' => 'Operating system', 'kernel' => 'Kernel', 'arch' => 'Architecture', 'cpus' => 'CPU count', 'container_runtime' => 'Runtime'] as $key => $label)
                <div><span class="text-neutral-500 dark:text-fg-dim">{{ $label }}</span><p>{{ data_get($node->metadata, $key, 'Unknown') }}</p></div>
            @endforeach
        </div>
    </x-application.settings-section>

    <x-application.settings-section title="Workloads" helper="Deploy assigned workload revisions through Flux and host-native Sentinel.">
        <x-slot:actions>
            <x-forms.button wire:click="refreshWorkloads" wire:loading.attr="disabled" wire:target="refreshWorkloads">Refresh state</x-forms.button>
        </x-slot:actions>
        @if ($node->workloads->isEmpty())
            <x-empty size="sm" title="No workloads assigned" description="Assign a workload to this node before deployment." icon-name="servers" />
        @else
            <div class="flex flex-col gap-3">
                @foreach ($node->workloads->sortBy('name') as $workload)
                    @php($revision = $workload->revisions->first())
                    @php($workloadState = data_get($workloadStates, $workload->uuid.'.status', 'Unknown'))
                    <div wire:key="node-workload-{{ $workload->uuid }}" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-neutral-200 px-4 py-3 dark:border-white/[0.08]">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-medium text-black dark:text-fg">{{ $workload->name }}</p>
                                <x-status-badge :status="$workloadState" :type="data_get($workloadStates, $workload->uuid.'.type', 'neutral')" />
                            </div>
                            @if ($revision)
                                <p class="truncate font-mono text-xs text-neutral-500 dark:text-fg-faint">{{ $revision->image }} · {{ $revision->uuid }}</p>
                            @else
                                <p class="text-xs text-neutral-500 dark:text-fg-faint">No revision is available.</p>
                            @endif
                            @can('update', $node)
                                <form wire:submit="saveWorkloadDnsName('{{ $workload->uuid }}')" class="mt-3 flex max-w-xl flex-col gap-2 sm:flex-row sm:items-end">
                                    <div class="min-w-0 flex-1">
                                        <x-forms.input wire:model="dnsNames.{{ $workload->uuid }}" label="Internal DNS name" maxlength="63" required />
                                        <p class="mt-1 truncate font-mono text-[10px] text-neutral-500 dark:text-fg-faint">.default.coolify.internal</p>
                                    </div>
                                    <x-forms.button type="submit" wire:loading.attr="disabled" wire:target="saveWorkloadDnsName('{{ $workload->uuid }}')">Save DNS name</x-forms.button>
                                </form>
                            @else
                                @if ($workload->internal_dns_name)
                                    <p class="mt-2 truncate font-mono text-[10px] text-neutral-500 dark:text-fg-faint">{{ $workload->internal_dns_name }}.default.coolify.internal</p>
                                @endif
                            @endcan
                        </div>
                        @if ($revision)
                            <div class="flex flex-wrap items-center gap-2">
                                <x-forms.button wire:click="deployRevision('{{ $revision->uuid }}')" wire:loading.attr="disabled" wire:target="deployRevision('{{ $revision->uuid }}')">
                                    {{ $workloadState === 'Running' ? 'Redeploy' : 'Deploy' }}
                                </x-forms.button>
                                @if ($workloadState === 'Running')
                                    <x-forms.button wire:click="manageWorkload('stop', '{{ $revision->uuid }}')" wire:loading.attr="disabled">Stop</x-forms.button>
                                    <x-forms.button wire:click="manageWorkload('restart', '{{ $revision->uuid }}')" wire:loading.attr="disabled">Restart</x-forms.button>
                                @elseif ($workloadState === 'Stopped')
                                    <x-forms.button wire:click="manageWorkload('start', '{{ $revision->uuid }}')" wire:loading.attr="disabled">Start</x-forms.button>
                                @endif
                                @if (in_array($workloadState, ['Running', 'Stopped', 'Outdated'], true))
                                    <x-forms.button isError wire:confirm="Remove this workload container from the node?" wire:click="manageWorkload('remove', '{{ $revision->uuid }}')" wire:loading.attr="disabled">Remove</x-forms.button>
                                @endif
                                @if ($node->cluster && $node->cluster->nodes->where('id', '!=', $node->id)->isNotEmpty())
                                    <form wire:submit="moveWorkload('{{ $workload->uuid }}')" class="flex items-end gap-2">
                                        <x-forms.select wire:model="moveTargets.{{ $workload->uuid }}" label="Move to Node" required>
                                            <option value="">Select a Node</option>
                                            @foreach ($node->cluster->nodes->where('id', '!=', $node->id)->sortBy('name') as $targetNode)
                                                <option value="{{ $targetNode->uuid }}">{{ $targetNode->name }}</option>
                                            @endforeach
                                        </x-forms.select>
                                        <x-forms.button type="submit" wire:loading.attr="disabled" wire:target="moveWorkload('{{ $workload->uuid }}')">Move</x-forms.button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($node->operations->isNotEmpty())
            <div class="mt-5 flex flex-col gap-2">
                <h3 class="text-sm font-medium">Recent operations</h3>
                @foreach ($node->operations as $operation)
                    <div wire:key="node-operation-{{ $operation->uuid }}" class="flex flex-wrap items-center justify-between gap-2 text-xs">
                        <span>
                            @if ($operation->command_type === 'workload.lifecycle.v1')
                                {{ str(data_get($operation->request, 'action'))->title() }} {{ $operation->workload?->name }}
                            @else
                                {{ $operation->workload?->name ?? $operation->command_type }}
                            @endif
                        </span>
                        <div class="flex items-center gap-2">
                            <span class="text-neutral-500 dark:text-fg-faint">{{ $operation->created_at->diffForHumans() }}</span>
                            <x-status-badge :status="match ($operation->status->value) {
                                'running' => 'Running command',
                                default => str($operation->status->value)->replace('_', ' ')->title(),
                            }" :type="match ($operation->status->value) {
                                'succeeded' => 'success',
                                'failed', 'timed_out' => 'error',
                                'uncertain' => 'warning',
                                default => 'neutral',
                            }" />
                            @if ($operation->status->value === 'uncertain')
                                <x-forms.button wire:click="retryOperation('{{ $operation->uuid }}')" wire:loading.attr="disabled" wire:target="retryOperation('{{ $operation->uuid }}')">Recover</x-forms.button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-application.settings-section>

    <x-application.settings-section title="Containers" helper="Read-only Podman inventory reported by Sentinel through Flux.">
        <x-slot:actions>
            <x-forms.button wire:click="refreshContainers" wire:loading.attr="disabled" wire:target="refreshContainers">
                <span wire:loading.remove wire:target="refreshContainers">Refresh containers</span>
                <span wire:loading wire:target="refreshContainers">Refreshing...</span>
            </x-forms.button>
        </x-slot:actions>
        @if ($node->containers->isEmpty())
            <x-empty size="sm" title="No containers found" description="Refresh the inventory to read containers from Podman." icon-name="servers" />
        @else
            <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-white/[0.08]">
                <div class="grid min-w-[680px] grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)_7rem_8rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                    <div>Container</div>
                    <div>Image</div>
                    <div>State</div>
                    <div>Ownership</div>
                </div>
                @foreach ($node->containers->sortBy('name') as $container)
                    <div wire:key="node-container-{{ $container->uuid }}" class="grid min-h-14 min-w-[680px] grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)_7rem_8rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] last:border-b-0 dark:border-white/[0.07]">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-black dark:text-fg">{{ $container->name }}</p>
                            <p class="truncate font-mono text-[10px] text-neutral-500 dark:text-fg-faint">{{ str($container->runtime_id)->limit(20) }}</p>
                        </div>
                        <p class="truncate font-mono text-[11px] text-neutral-600 dark:text-fg-dim">{{ $container->image }}</p>
                        <div>
                            <x-status-badge :status="str($container->state)->title()" :type="$container->state === 'running' ? 'success' : 'warning'" />
                        </div>
                        <div>
                            <x-status-badge :status="str($container->management_state->value)->title()" :type="match ($container->management_state->value) {
                                'managed' => 'success',
                                'unrecognized' => 'warning',
                                default => 'neutral',
                            }" />
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-application.settings-section>
        </div>
    </div>
</div>
