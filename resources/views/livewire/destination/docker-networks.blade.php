<section wire:init="refreshNetworksInBackground" class="flex flex-col gap-4">
    <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-3">
            <h2>Networks</h2>
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)] lg:items-end">
                <div class="w-full">
                    <x-forms.input id="search" label="Search" placeholder="Name or subnet..." />
                </div>
                @if (count($serverOptions) > 0)
                    <div class="w-full">
                        <x-forms.select id="selectedInventoryServerUuid" label="Server"
                            x-on:change="window.location.href = '{{ route('destination.index') }}?server=' + encodeURIComponent($event.target.value)">
                            @foreach ($serverOptions as $serverOption)
                                <option value="{{ $serverOption['uuid'] }}">{{ $serverOption['name'] }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>
                @endif
                <div class="w-full">
                    <x-forms.select id="filter" label="Filter">
                        <option value="all">All</option>
                        <option value="managed">Created by Coolify</option>
                        <option value="external">External</option>
                        <option value="system">System</option>
                        <option value="active">Active</option>
                    </x-forms.select>
                </div>
            </div>
        </div>

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
            <div class="flex flex-wrap gap-3 text-xs text-neutral-500">
                <span>Refreshed.</span>
                <span>Found: {{ $scanSummary['found'] }}</span>
                @if ($scanSummary['created'] > 0)
                    <span>Created: {{ $scanSummary['created'] }}</span>
                @endif
                @if ($scanSummary['updated'] > 0)
                    <span>Updated: {{ $scanSummary['updated'] }}</span>
                @endif
                @if ($scanSummary['removed'] > 0)
                    <span>Removed: {{ $scanSummary['removed'] }}</span>
                @endif
            </div>
        @endif

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
                            @php($destination = $destinationsByNetwork->get($network->docker_network_name))
                            <tr wire:key="docker-network-{{ $network->id }}" class="border-b border-neutral-900">
                                <td class="px-3 py-3">
                                    <div class="font-medium">{{ $network->display_name }}</div>
                                    <div class="font-mono text-xs text-neutral-500">{{ $network->docker_network_name }}</div>
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
                                            :status="$network->managed_by_coolify ? 'Created by Coolify' : 'External to Coolify'"
                                            :type="$network->managed_by_coolify ? 'success' : 'neutral'" />
                                        <x-status-badge
                                            :status="$network->is_system ? 'System' : 'Custom'"
                                            :type="$network->is_system ? 'warning' : 'neutral'" />
                                        <x-status-badge
                                            :status="$network->is_active ? 'Active' : 'Inactive'"
                                            :type="$network->is_active ? 'success' : 'neutral'" />
                                        @if ($destination)
                                            <x-status-badge status="Destination" type="success" />
                                        @endif
                                        <x-status-badge
                                            :status="$this->proxyAccessLabel($network)"
                                            :type="$this->proxyAccessType($network)" />
                                        @if ($network->internal)
                                            <x-status-badge status="Internal" type="warning" />
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-3">{{ $this->containerCount($network) }}</td>
                                <td class="px-3 py-3 text-sm">{{ $network->last_inspected_at?->diffForHumans() ?? '-' }}</td>
                                <td class="px-3 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        @if ($destination)
                                            @can('view', $destination)
                                                <a {{ wireNavigate() }}
                                                    href="{{ route('destination.show', ['destination_uuid' => $destination->uuid]) }}">
                                                    <x-forms.button>View Destination</x-forms.button>
                                                </a>
                                            @endcan
                                            @can('delete', $destination)
                                                @if ($this->canRemoveFromDestinations($network))
                                                    <x-modal-confirmation title="Remove Destination?"
                                                        buttonTitle="Remove Destination" isErrorButton
                                                        submitAction="removeFromDestinations({{ $network->id }})"
                                                        :actions="['Remove this network from Destinations.']"
                                                        safeTitle="Remove from Destinations"
                                                        safeButtonTitle="Remove Destination"
                                                        safeMessage="This removes the network from Destinations. The Docker network will not be deleted."
                                                        warningMessage="The real Docker network will be permanently deleted. This operation cannot be undone."
                                                        :safeActions="[
                                                            'Remove this network from Destinations.',
                                                            'Keep the Docker network on the server.',
                                                            'Keep existing runtime containers and network data.',
                                                            'Keep the network available in network management.',
                                                            'Allow this network to be added as a Destination again later.',
                                                        ]"
                                                        :permanentActions="[
                                                            'Permanently remove the real Docker network: '.$network->docker_network_name,
                                                            'Remove the Destination association.',
                                                            'Remove local network inventory and metadata after Docker confirms deletion.',
                                                        ]"
                                                        :checkboxes="[[
                                                            'id' => 'deleteNetwork',
                                                            'label' => 'Delete Docker network permanently.',
                                                            'default_warning' => 'Keep Docker network on the server.',
                                                        ]]"
                                                        confirmationText="{{ $network->docker_network_name }}"
                                                        confirmationLabel="Please confirm permanent deletion by entering the Docker network name below"
                                                        shortConfirmationLabel="Docker network name"
                                                        confirmWithTextAction="deleteNetwork"
                                                        :initialActions="[]"
                                                        :inlineActionSelection="true"
                                                        :confirmWithPassword="false"
                                                        step1ButtonText="Continue"
                                                        step2ButtonText="Confirm" />
                                                @endif
                                            @endcan
                                        @elseif ($this->canUseAsDestination($network))
                                            <x-forms.button canGate="create" :canResource="\App\Models\StandaloneDocker::class"
                                                wire:click="useAsDestination({{ $network->id }})">
                                                Use as Destination
                                            </x-forms.button>
                                        @endif
                                        <x-forms.button wire:click="selectNetwork({{ $network->id }})">Inspect</x-forms.button>
                                        @if ($this->canEditNetworkAlias($network))
                                            <x-forms.button canGate="update" :canResource="$server" wire:click="startEditing({{ $network->id }})">Edit alias</x-forms.button>
                                        @endif
                                        @if (! $network->is_system && $network->is_active)
                                            <x-forms.button canGate="update" :canResource="$server"
                                                wire:click="updateProxyAccess({{ $network->id }}, {{ $network->proxy_access === true ? 'false' : 'true' }})">
                                                {{ $network->proxy_access === true ? 'Disable' : 'Enable' }} proxy access
                                            </x-forms.button>
                                        @endif
                                        @if ($this->canDeleteNetwork($network))
                                            <span>
                                                <x-modal-confirmation title="Confirm Docker Network Deletion?"
                                                    buttonTitle="Delete network" isErrorButton
                                                    submitAction="deleteNetwork({{ $network->id }})"
                                                    :actions="[
                                                        ...($destination ? ['Remove the Destination association.'] : []),
                                                        'Permanently remove network alias: '.($network->display_name ?: $network->docker_network_name),
                                                        'Permanently remove Docker network: '.$network->docker_network_name,
                                                        'Remove the local live inventory record after Docker confirms deletion.',
                                                    ]"
                                                    warningMessage="This permanently removes the real Docker network from the selected server."
                                                    safeMessage="This network will be permanently deleted."
                                                    :permanentActions="[
                                                        ...($destination ? ['Remove the Destination association.'] : []),
                                                        'Permanently remove the real Docker network: '.$network->docker_network_name,
                                                        'Remove local network inventory and metadata after Docker confirms deletion.',
                                                    ]"
                                                    :initialActions="['deleteNetwork']"
                                                    confirmWithTextAction="deleteNetwork"
                                                    confirmationText="{{ $network->docker_network_name }}"
                                                    confirmationLabel="Please confirm by entering the Docker network name below"
                                                    shortConfirmationLabel="Docker network name"
                                                    step2ButtonText="Permanently Delete" />
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($showInspectModal && $selectedNetwork)
        <template x-teleport="body">
            <div x-data="{ modalOpen: true }" x-show="modalOpen" x-init="$watch('modalOpen', value => { if (!value) { $wire.closeInspect() } })"
                @keydown.window.escape="modalOpen=false" class="fixed inset-0 z-99 overflow-y-auto">
                <div x-show="modalOpen" x-transition.opacity class="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs"></div>
                <div @click.self="modalOpen=false" class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition
                        class="relative flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden rounded-sm border border-neutral-200 bg-white drop-shadow-sm dark:border-coolgray-300 dark:bg-base lg:max-w-5xl">
                        <div class="flex shrink-0 items-center justify-between px-6 py-6">
                            <div>
                                <h3 class="text-2xl font-bold">Inspect {{ $selectedNetwork->display_name }}</h3>
                                <div class="font-mono text-xs text-neutral-500">{{ $selectedNetwork->docker_network_name }}</div>
                            </div>
                            <button @click="modalOpen=false"
                                class="absolute right-0 top-0 mr-5 mt-5 flex h-8 w-8 cursor-pointer items-center justify-center rounded-full outline-0 hover:bg-neutral-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs focus-visible:ring-offset-2 dark:text-white dark:hover:bg-coolgray-300 dark:focus-visible:ring-warning dark:focus-visible:ring-offset-base">
                                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <div class="relative min-h-0 flex-1 overflow-y-auto px-6 pb-6 pt-1" style="-webkit-overflow-scrolling: touch;">
                            <div class="flex flex-col gap-6">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="text-sm text-neutral-500">Last inspected: {{ $selectedNetwork->last_inspected_at?->diffForHumans() ?? '-' }}</div>
                                    <x-forms.button canGate="update" :canResource="$server" wire:click="refreshInspect" :disabled="!$serverIsFunctional">
                                        Refresh inspect
                                    </x-forms.button>
                                </div>

                                <div class="grid grid-cols-1 gap-3 text-sm lg:grid-cols-3">
                                    <div><span class="text-neutral-500">Docker ID:</span> {{ data_get($selectedNetwork->last_inspect_data, 'docker_id', '-') }}</div>
                                    <div><span class="text-neutral-500">Name:</span> {{ $selectedNetwork->docker_network_name }}</div>
                                    <div><span class="text-neutral-500">Driver:</span> {{ $selectedNetwork->driver?->value ?? 'unknown' }}</div>
                                    <div><span class="text-neutral-500">Scope:</span> {{ $selectedNetwork->scope?->value ?? 'unknown' }}</div>
                                    <div><span class="text-neutral-500">Enable IPv6:</span> {{ $selectedNetwork->enable_ipv6 ? 'Yes' : 'No' }}</div>
                                    <div><span class="text-neutral-500">Internal:</span> {{ $selectedNetwork->internal ? 'Yes' : 'No' }}</div>
                                    <div><span class="text-neutral-500">Proxy access:</span> {{ $this->proxyAccessLabel($selectedNetwork) }}</div>
                                    <div><span class="text-neutral-500">Created by Coolify:</span> {{ $selectedNetwork->managed_by_coolify ? 'Yes' : 'No' }}</div>
                                    <div><span class="text-neutral-500">Ingress:</span> {{ data_get($selectedNetwork->last_inspect_data, 'raw.Ingress') ? 'Yes' : 'No' }}</div>
                                    <div><span class="text-neutral-500">Config only:</span> {{ data_get($selectedNetwork->last_inspect_data, 'raw.ConfigOnly') ? 'Yes' : 'No' }}</div>
                                </div>

                                <div>
                                    <h4 class="mb-2">IPAM</h4>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full text-sm">
                                            <thead>
                                                <tr class="border-b border-neutral-800 text-left text-xs uppercase text-neutral-500">
                                                    <th class="px-3 py-2">Config</th>
                                                    <th class="px-3 py-2">Subnet</th>
                                                    <th class="px-3 py-2">Gateway</th>
                                                    <th class="px-3 py-2">Allocation Range</th>
                                                    <th class="px-3 py-2">Aux Addresses</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($this->inspectIpamConfigs($selectedNetwork) as $config)
                                                    <tr class="border-b border-neutral-900">
                                                        <td class="px-3 py-2">{{ $config['label'] }}</td>
                                                        <td class="px-3 py-2">{{ $config['subnet'] }}</td>
                                                        <td class="px-3 py-2">{{ $config['gateway'] }}</td>
                                                        <td class="px-3 py-2">{{ $config['ip_range'] }}</td>
                                                        <td class="px-3 py-2">
                                                            {{ collect($config['aux_addresses'])->map(fn ($value, $key) => $key.': '.$value)->join(', ') ?: 'Not configured' }}
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div>
                                    <h4 class="mb-2">Labels</h4>
                                    <div class="text-sm">
                                        {{ collect($selectedNetwork->labels ?: [])->map(fn ($value, $key) => $key.'='.$value)->join(', ') ?: 'Not configured' }}
                                    </div>
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
                                                            <td class="px-3 py-2">{{ data_get($container, 'IPv4Address', '-') ?: '-' }}</td>
                                                            <td class="px-3 py-2">{{ data_get($container, 'IPv6Address', '-') ?: '-' }}</td>
                                                            <td class="px-3 py-2">{{ data_get($container, 'MacAddress', '-') ?: '-' }}</td>
                                                            <td class="px-3 py-2">{{ collect(data_get($container, 'Aliases', []))->join(', ') ?: '-' }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @endif
                                </div>

                                <details class="text-sm">
                                    <summary class="cursor-pointer text-neutral-500">Raw Docker inspect JSON</summary>
                                    <pre class="mt-2 max-h-96 overflow-auto rounded-sm bg-black/10 p-3 text-xs dark:bg-black/30">{{ json_encode(data_get($selectedNetwork->last_inspect_data, 'raw', []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    @endif
</section>
