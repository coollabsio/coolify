<div wire:init="refreshNetworksInBackground">
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Docker Networks | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex h-full flex-col gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="docker-networks" />
        <div class="w-full">
            <div class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center gap-2">
                    <h2>Docker Networks</h2>
                    <x-forms.button canGate="update" :canResource="$server" wire:click="scan" :disabled="!$serverIsFunctional">
                        Refresh
                    </x-forms.button>
                    <x-forms.button canGate="update" :canResource="$server" wire:click="$toggle('showCreateForm')" :disabled="!$serverIsFunctional">
                        Create network
                    </x-forms.button>
                    <x-forms.button canGate="update" :canResource="$server" wire:click="$toggle('showImportForm')" :disabled="!$serverIsFunctional">
                        Import existing network
                    </x-forms.button>
                </div>
                <div class="subtitle">Discover and manage Docker networks on this server.</div>

                <div wire:loading.flex wire:target="refreshNetworksInBackground" class="text-xs text-neutral-500">
                    Refreshing networks...
                </div>

                @if (!$serverIsFunctional)
                    <x-callout type="warning" title="Server unavailable">
                        Server is not functional. Runtime network actions are unavailable.
                    </x-callout>
                @endif

                @if ($refreshWarning)
                    <x-callout type="warning" title="Refresh unavailable">
                        {{ $refreshWarning }}
                    </x-callout>
                @endif

                @if ($scanSummary)
                    <div class="box-without-bg flex flex-wrap gap-4 text-sm">
                        <span>Scan completed.</span>
                        <span>Found: {{ $scanSummary['found'] }}</span>
                        <span>Created: {{ $scanSummary['created'] }}</span>
                        <span>Updated: {{ $scanSummary['updated'] }}</span>
                        <span>Marked inactive: {{ $scanSummary['marked_inactive'] }}</span>
                    </div>
                @endif

                @if ($showCreateForm)
                    <div class="box-without-bg">
                        <form wire:submit="createNetwork" class="flex flex-col gap-4">
                            <div class="flex items-center justify-between gap-4">
                                <h3>Create Managed Network</h3>
                                <x-forms.button wire:click="resetCreateForm">Cancel</x-forms.button>
                            </div>
                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                <x-forms.input id="createDisplayName" label="Display name" required />
                                <x-forms.select id="createDriver" label="Driver" required>
                                    <option value="bridge">bridge</option>
                                </x-forms.select>
                                <x-forms.input id="createSubnet" label="Subnet" placeholder="172.30.10.0/24" />
                                <x-forms.input id="createGateway" label="Gateway" placeholder="172.30.10.1" />
                                <x-forms.checkbox id="createInternal" label="Internal" />
                                <x-forms.checkbox id="createAttachable" label="Attachable" />
                            </div>
                            <div>
                                <x-forms.button type="submit" isHighlighted>Create network</x-forms.button>
                            </div>
                        </form>
                    </div>
                @endif

                @if ($showImportForm)
                    <div class="box-without-bg">
                        <form wire:submit="importNetwork" class="flex flex-col gap-4">
                            <div class="flex items-center justify-between gap-4">
                                <h3>Import Existing Network</h3>
                                <x-forms.button wire:click="resetImportForm">Cancel</x-forms.button>
                            </div>
                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                <x-forms.input id="importNetworkName" label="Docker network name" required />
                                <x-forms.input id="importDisplayName" label="Display name" />
                            </div>
                            <div class="text-sm text-neutral-500">
                                Reserved system networks like @foreach($reservedImportNetworkNames as $index => $name)<span class="font-mono text-xs">{{ $name }}</span>{{ $index < count($reservedImportNetworkNames) - 1 ? ', ' : '' }}@endforeach cannot be imported.
                            </div>
                            <div>
                                <x-forms.button type="submit" isHighlighted>Import network</x-forms.button>
                            </div>
                        </form>
                    </div>
                @endif

                <div class="flex flex-col gap-3 xl:flex-row xl:items-end">
                    <div class="w-full xl:max-w-md">
                        <x-forms.input id="search" label="Search" placeholder="Name or subnet..." />
                    </div>
                    <div class="w-full xl:max-w-xs">
                        <x-forms.select id="filter" label="Filter">
                            <option value="all">All</option>
                            <option value="managed">Managed</option>
                            <option value="external">External</option>
                            <option value="system">System</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </x-forms.select>
                    </div>
                </div>

                @if ($networks->isEmpty())
                    <div class="box-without-bg text-sm text-neutral-500">
                        No Docker networks known yet. Coolify refreshes this list automatically when the page opens.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead>
                                <tr class="border-b border-neutral-800 text-left text-xs uppercase text-neutral-500">
                                    <th class="px-3 py-3">Display Name</th>
                                    <th class="px-3 py-3">Driver</th>
                                    <th class="px-3 py-3">Subnet</th>
                                    <th class="px-3 py-3">Role</th>
                                    <th class="px-3 py-3">Status</th>
                                    <th class="px-3 py-3">Containers</th>
                                    <th class="px-3 py-3">Last Inspected</th>
                                    <th class="px-3 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($networks as $network)
                                    <tr wire:key="docker-network-{{ $network->id }}" class="border-b border-neutral-900">
                                        <td class="px-3 py-3">
                                            <div class="font-medium">{{ $network->display_name }}</div>
                                            @if ($editingNetworkId === $network->id)
                                                <form wire:submit="renameNetwork" class="mt-2 flex min-w-72 gap-2">
                                                    <x-forms.input id="editDisplayName" />
                                                    <x-forms.button type="submit">Save</x-forms.button>
                                                </form>
                                                <div class="mt-1 text-xs text-neutral-500">Docker technical name cannot be changed.</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3">{{ $network->driver?->value ?? 'unknown' }} / {{ $network->scope?->value ?? 'unknown' }}</td>
                                        <td class="px-3 py-3">
                                            <div>{{ $network->subnet ?: '-' }}</div>
                                            @if ($network->gateway)
                                                <div class="text-xs text-neutral-500">Gateway: {{ $network->gateway }}</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3">
                                            <x-status-badge :status="$this->roleLabel($network)" type="neutral" />
                                        </td>
                                        <td class="px-3 py-3">
                                            <div class="flex flex-wrap gap-1">
                                                <x-status-badge
                                                    :status="$network->managed_by_coolify ? 'Managed' : 'External'"
                                                    :type="$network->managed_by_coolify ? 'success' : 'neutral'" />
                                                <x-status-badge
                                                    :status="$network->is_system ? 'System' : 'Custom'"
                                                    :type="$network->is_system ? 'warning' : 'neutral'" />
                                                <x-status-badge
                                                    :status="$network->is_active ? 'Active' : 'Inactive'"
                                                    :type="$network->is_active ? 'success' : 'neutral'" />
                                            </div>
                                        </td>
                                        <td class="px-3 py-3">{{ $this->containerCount($network) }}</td>
                                        <td class="px-3 py-3 text-sm">{{ $network->last_inspected_at?->diffForHumans() ?? '-' }}</td>
                                        <td class="px-3 py-3">
                                            <div class="flex flex-wrap gap-2">
                                                <x-forms.button wire:click="selectNetwork({{ $network->id }})">Inspect</x-forms.button>
                                                <x-forms.button canGate="update" :canResource="$server" wire:click="startEditing({{ $network->id }})">Edit alias</x-forms.button>
                                                <x-forms.button canGate="update" :canResource="$server"
                                                    x-on:click="if (confirm('This will remove the Docker network from the server if no containers are connected.')) { $wire.deleteNetwork({{ $network->id }}) }"
                                                    :disabled="!$this->deleteEnabled($network)">
                                                    Delete
                                                </x-forms.button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($selectedNetwork)
                    <div class="box-without-bg flex flex-col gap-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3>Inspect {{ $selectedNetwork->display_name }}</h3>
                            <x-forms.button canGate="update" :canResource="$server" wire:click="refreshInspect" :disabled="!$serverIsFunctional">
                                Refresh inspect
                            </x-forms.button>
                        </div>
                        <div class="grid grid-cols-1 gap-3 text-sm lg:grid-cols-3">
                            <div><span class="text-neutral-500">Docker ID:</span> {{ data_get($selectedNetwork->last_inspect_data, 'docker_id', '-') }}</div>
                            <div><span class="text-neutral-500">Docker Network Name:</span> {{ $selectedNetwork->docker_network_name }}</div>
                            <div><span class="text-neutral-500">Driver:</span> {{ $selectedNetwork->driver?->value ?? 'unknown' }}</div>
                            <div><span class="text-neutral-500">Scope:</span> {{ $selectedNetwork->scope?->value ?? 'unknown' }}</div>
                            <div><span class="text-neutral-500">IP Range:</span> {{ $selectedNetwork->ip_range ?: '-' }}</div>
                            <div><span class="text-neutral-500">Enable IPv6:</span> {{ $selectedNetwork->enable_ipv6 ? 'Yes' : 'No' }}</div>
                            <div><span class="text-neutral-500">Internal:</span> {{ $selectedNetwork->internal ? 'Yes' : 'No' }}</div>
                            <div><span class="text-neutral-500">Attachable:</span> {{ $selectedNetwork->attachable ? 'Yes' : 'No' }}</div>
                            <div><span class="text-neutral-500">Managed by Coolify:</span> {{ $selectedNetwork->managed_by_coolify ? 'Yes' : 'No' }}</div>
                        </div>

                        <div>
                            <h4 class="mb-2">Connected Containers</h4>
                            @php($containers = data_get($selectedNetwork->last_inspect_data, 'containers', []))
                            @if (empty($containers))
                                <div class="text-sm text-neutral-500">No containers connected.</div>
                            @else
                                <div class="overflow-x-auto">
                                    <table class="min-w-full text-sm">
                                        <thead>
                                            <tr class="border-b border-neutral-800 text-left text-xs uppercase text-neutral-500">
                                                <th class="px-3 py-2">Container Name</th>
                                                <th class="px-3 py-2">Container ID</th>
                                                <th class="px-3 py-2">IPv4</th>
                                                <th class="px-3 py-2">IPv6</th>
                                                <th class="px-3 py-2">MAC Address</th>
                                                <th class="px-3 py-2">Aliases</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($containers as $containerId => $container)
                                                <tr class="border-b border-neutral-900">
                                                    <td class="px-3 py-2">{{ data_get($container, 'Name', '-') }}</td>
                                                    <td class="px-3 py-2 font-mono text-xs">{{ $containerId }}</td>
                                                    <td class="px-3 py-2">{{ data_get($container, 'IPv4Address', '-') }}</td>
                                                    <td class="px-3 py-2">{{ data_get($container, 'IPv6Address', '-') }}</td>
                                                    <td class="px-3 py-2">{{ data_get($container, 'MacAddress', '-') }}</td>
                                                    <td class="px-3 py-2">{{ collect(data_get($container, 'Aliases', []))->join(', ') }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
