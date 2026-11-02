@php
    $canUpdateCluster = auth()->user()->can('update', $cluster);
    $networkActivated = $cluster->hasActivatedNetwork();
    $cidrLocked = $networkActivated || $cluster->nodes()->exists();
@endphp

<x-application.settings-section id="node-cluster-private-network-section" title="Private network"
    helper="The private CIDR can change only while the cluster has no servers. The network settings are locked after activation.">
    <form wire:submit="saveSettings" class="flex flex-col gap-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <x-forms.input id="cidr" label="Private CIDR" required :disabled="$cidrLocked"
                canGate="update" :canResource="$cluster"
                :helper="$networkActivated ? 'The network is active, so the CIDR is locked.' : ($cidrLocked ? 'Remove all servers to change the CIDR.' : null)" />
            <x-forms.input id="wireguardInterface" label="WireGuard interface" required canGate="update"
                :canResource="$cluster" :disabled="$networkActivated" />
            <x-forms.input id="wireguardPort" type="number" min="1" max="65535" label="UDP port" required
                canGate="update" :canResource="$cluster" :disabled="$networkActivated" />
        </div>
        @if ($canUpdateCluster)
            <div class="flex">
                <x-forms.button type="submit">Save</x-forms.button>
            </div>
        @endif
    </form>
</x-application.settings-section>

<x-application.settings-section id="node-cluster-deployment-limits-section" title="Deployment limits"
    helper="Block new deployments when a selected server is unavailable, its resource data is stale, or usage reaches a limit. A value of 100 blocks only when that resource is fully used. Running applications are not stopped.">
    <form wire:submit="savePressurePolicy" class="flex flex-col gap-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-forms.input id="cpuPressureThreshold" type="number" min="1" max="100" label="CPU limit (%)" required
                canGate="update" :canResource="$cluster" />
            <x-forms.input id="memoryPressureThreshold" type="number" min="1" max="100" label="Memory limit (%)"
                required canGate="update" :canResource="$cluster" />
            <x-forms.input id="diskPressureThreshold" type="number" min="1" max="100" label="Disk limit (%)" required
                canGate="update" :canResource="$cluster" />
            <x-forms.input id="resourceStaleAfterMinutes" type="number" min="1" max="60"
                label="Data max age (minutes)" required canGate="update" :canResource="$cluster" />
        </div>
        @if ($canUpdateCluster)
            <div class="flex">
                <x-forms.button type="submit">Save</x-forms.button>
            </div>
        @endif
    </form>
</x-application.settings-section>

@if ($cluster->registry)
    <x-application.settings-section id="node-cluster-registry-section" title="Image registry"
        helper="Git applications push their images to the registry of the cluster. By default it runs on the first available server and moves when that server is offline. A selected server is kept, also while it is offline.">
        @php
            $registryOptions = collect([['value' => '', 'label' => 'Automatic (first available server)']])
                ->concat($cluster->nodes->sortBy('id')->map(fn ($node) => ['value' => $node->uuid, 'label' => $node->name]))
                ->values()->all();
            $selectedRegistryNode = $cluster->registry->selectedNode?->uuid ?? '';
        @endphp
        <div class="flex flex-col gap-3" wire:init="loadRegistryReport">
            <div class="flex flex-wrap items-center gap-3">
                <p class="text-sm text-neutral-600 dark:text-fg-dim">
                    Runs on: <span class="font-medium text-black dark:text-fg">{{ $cluster->registry->node?->name ?? 'No available server' }}</span>
                </p>
                @if ($registryReport === null)
                    <x-status-badge label="Checking" type="neutral" spinning />
                @elseif ($registryReport['healthy'] === true)
                    <x-status-badge label="Healthy" type="success" />
                @elseif ($registryReport['healthy'] === false)
                    <x-status-badge label="Unhealthy" type="error" />
                @endif
                <x-forms.button class="ml-auto" wire:click="loadRegistryReport" wire:loading.attr="disabled"
                    wire:target="loadRegistryReport">Refresh</x-forms.button>
            </div>
            @if (filled($registryReport['error'] ?? null))
                <x-callout type="danger" title="The registry does not answer">{{ $registryReport['error'] }}</x-callout>
            @endif
            @if ($canUpdateCluster)
                <div class="flex flex-wrap gap-2">
                    @foreach ($registryOptions as $option)
                        <x-forms.button wire:click="setRegistryNode('{{ $option['value'] }}')"
                            wire:loading.attr="disabled" wire:target="setRegistryNode"
                            :isHighlighted="$selectedRegistryNode === $option['value']">{{ $option['label'] }}</x-forms.button>
                    @endforeach
                </div>
            @endif
            @if ($registryReport !== null)
                <x-application.settings-subsection title="Disk use">
                    <div class="data-table">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-neutral-500 dark:text-fg-faint">
                                    <th class="py-2 pr-4 font-medium">Server</th>
                                    <th class="py-2 pr-4 font-medium">Registry</th>
                                    <th class="py-2 pr-4 font-medium">Build cache</th>
                                    <th class="py-2 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($registryReport['nodes'] as $row)
                                    <tr wire:key="registry-node-{{ $row['name'] }}" class="border-t border-neutral-200 dark:border-white/[0.07]">
                                        <td class="py-2 pr-4 font-medium text-black dark:text-fg">{{ $row['name'] }}</td>
                                        <td class="py-2 pr-4">{{ $row['registry_running'] || $row['registry_bytes'] > 0 ? formatBytes($row['registry_bytes'], 1) : '–' }}</td>
                                        <td class="py-2 pr-4">{{ $row['builder_running'] || $row['build_cache_bytes'] > 0 ? formatBytes($row['build_cache_bytes'], 1) : '–' }}</td>
                                        <td class="py-2 text-neutral-500 dark:text-fg-dim">
                                            {{ $row['error'] ?? collect([$row['registry_running'] ? 'Registry' : null, $row['builder_running'] ? 'Builder' : null])->filter()->implode(' · ') ?: 'Not used' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-application.settings-subsection>
                <x-application.settings-subsection title="Images">
                    @forelse ($registryReport['repositories'] as $repository)
                        <div wire:key="registry-repository-{{ $repository['name'] }}" class="border-t border-neutral-200 py-3 first:border-t-0 dark:border-white/[0.07]">
                            <div class="flex flex-wrap items-baseline gap-2">
                                @if ($repository['workload'] && $repository['workload']['project_uuid'] && $repository['workload']['environment_uuid'])
                                    <a class="text-sm font-medium text-black underline-offset-2 hover:underline dark:text-fg" {{ wireNavigate() }}
                                        href="{{ route('project.cluster-application.show', ['project_uuid' => $repository['workload']['project_uuid'], 'environment_uuid' => $repository['workload']['environment_uuid'], 'workload_uuid' => $repository['workload']['uuid']]) }}">{{ $repository['workload']['name'] }}</a>
                                @else
                                    <span class="text-sm font-medium text-black dark:text-fg">Deleted application</span>
                                @endif
                                <span class="font-mono text-xs text-neutral-500 dark:text-fg-faint">{{ $repository['name'] }}</span>
                                <span class="text-xs text-neutral-500 dark:text-fg-faint">· {{ count($repository['tags']) }} {{ Str::plural('image', count($repository['tags'])) }}</span>
                            </div>
                            <ul class="mt-2 space-y-1">
                                @foreach ($repository['tags'] as $tag)
                                    <li wire:key="registry-tag-{{ $repository['name'] }}-{{ $tag['tag'] }}" class="flex flex-wrap items-center gap-2 text-xs">
                                        <span class="font-mono text-neutral-700 dark:text-fg-dim">{{ $tag['tag'] }}</span>
                                        <span class="font-mono text-neutral-400 dark:text-fg-faint">{{ $tag['digest'] ? Str::limit($tag['digest'], 19, '') : 'digest unknown' }}</span>
                                        @if ($tag['deployed'])
                                            <x-status-badge label="Deployed" type="success" />
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @empty
                        <p class="text-sm text-neutral-500 dark:text-fg-dim">{{ $registryReport['healthy'] ? 'The registry stores no images yet.' : 'The image list is not available.' }}</p>
                    @endforelse
                    @if ($registryReport['truncated'])
                        <p class="mt-2 text-xs text-neutral-500 dark:text-fg-faint">Showing the first 100 repositories and 50 images of each.</p>
                    @endif
                </x-application.settings-subsection>
            @endif
        </div>
    </x-application.settings-section>
@endif

<x-application.settings-section id="node-cluster-troubleshooting-section" title="Troubleshooting"
    helper="Use repair only when servers lost their private network connection and a sync cannot reach them." flush>
    @if ($canUpdateCluster)
        <x-slot:actions>
            <x-modal-confirmation title="Repair cluster network?" buttonTitle="Repair network over SSH"
                submitAction="repairNetwork" :actions="[
                    'The last known good network and firewall state is restored on every server over SSH.',
                    'Run a network sync afterwards to verify the current state.',
                ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Repair network" />
        </x-slot:actions>
    @endif

    <div class="border-b border-neutral-200 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
        Recent operations
    </div>
    @forelse ($operations as $operation)
        @php
            $operationStatus = $operation->status->value;
            $operationStatusType = match ($operationStatus) {
                'succeeded' => 'success',
                'failed', 'timed_out' => 'error',
                'cancelled' => 'neutral',
                default => 'warning',
            };
        @endphp
        <div wire:key="cluster-operation-{{ $operation->uuid }}"
            class="flex min-h-12 items-center justify-between gap-3 border-b border-neutral-200 px-4 py-2 last:border-b-0 dark:border-white/[0.07]">
            <div class="min-w-0">
                <p class="truncate text-[13px] font-medium text-black dark:text-fg">
                    {{ str($operation->command_type)->replace('.v1', '')->replace('.', ' ')->title() }}
                </p>
                <p class="truncate text-[11px] text-neutral-500 dark:text-fg-dim">
                    {{ $operation->node->name }} · {{ $operation->created_at->diffForHumans() }}
                </p>
            </div>
            <x-status-badge :status="str($operationStatus)->replace('_', ' ')->title()" :type="$operationStatusType" />
        </div>
    @empty
        <p class="px-4 py-3 text-[13px] text-neutral-500 dark:text-fg-dim">No operations yet.</p>
    @endforelse
</x-application.settings-section>
