<div wire:init="load">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h2>Container Info</h2>
                <p class="text-sm text-neutral-500 dark:text-fg-dim">
                    Live details read from the Docker daemon for every container of this resource.
                </p>
            </div>
            <x-forms.button wire:click="load">Refresh</x-forms.button>
        </div>

        @if (! $loaded)
            <x-loading text="Reading container details" />
        @elseif (empty($containers))
            <x-callout type="info" title="No containers found">
                Either the resource is not deployed, the server is unreachable, or it runs in Swarm mode
                (not supported here yet).
            </x-callout>
        @endif

        @foreach ($containers as $container)
            <x-application.settings-section wire:key="container-info-{{ $container['name'] }}"
                :title="$container['name']">
                <x-slot:actions>
                    <x-status-badge :status="str($container['status'] ?? 'unknown')->headline()->value()"
                        :type="$container['status'] === 'running' ? 'success' : ($container['status'] === 'exited' ? 'error' : 'warning')" />
                </x-slot:actions>

                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <x-forms.copy-input label="Container name" :text="$container['name']" />
                    <x-forms.copy-input label="Container ID" :text="$container['id'] ?? ''" />
                    <x-forms.copy-input label="Image" :text="$container['image'] ?? ''" />
                    <x-forms.copy-input label="Image ID" :text="$container['image_id'] ?? ''" />
                    <x-forms.copy-input label="Created" :text="$container['created'] ?? 'Unknown'" />
                    <x-forms.copy-input label="Started" :text="$container['started'] ?? 'Not started'" />
                    <x-forms.copy-input label="Restart count" :text="(string) $container['restart_count']" />
                </div>

                <h4 class="mt-6 mb-2 text-sm font-semibold text-black dark:text-fg">Networks</h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-neutral-500 dark:text-fg-dim">
                            <tr>
                                <th class="py-2 pr-4 font-medium">Network</th>
                                <th class="py-2 pr-4 font-medium">IPv4</th>
                                <th class="py-2 pr-4 font-medium">IPv6</th>
                                <th class="py-2 pr-4 font-medium">Gateway</th>
                                <th class="py-2 pr-4 font-medium">MAC</th>
                                <th class="py-2 pr-4 font-medium">Aliases</th>
                                <th class="py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                            @forelse ($container['networks'] as $network)
                                <tr wire:key="container-info-{{ $container['name'] }}-{{ $network['name'] }}">
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $network['name'] }}</td>
                                    @foreach (['ipv4', 'ipv6', 'gateway', 'mac'] as $field)
                                        <td class="py-2 pr-4 font-mono text-xs">
                                            @if ($network[$field])
                                                <span class="inline-flex items-center gap-1">{{ $network[$field] }}
                                                    <x-copy-button :value="$network[$field]" /></span>
                                            @else
                                                <span class="text-neutral-400">-</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="py-2 pr-4 text-xs">{{ $network['aliases'] ?: '-' }}</td>
                                    <td class="py-2 text-right">
                                        <x-forms.button isError canGate="update" :canResource="$resource"
                                            :disabled="count($container['networks']) <= 1"
                                            wire:click="disconnect('{{ $container['name'] }}', '{{ $network['name'] }}')">
                                            Disconnect
                                        </x-forms.button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-2 text-neutral-500 dark:text-fg-dim">Not connected to any network.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @php $others = array_values(array_diff($availableNetworks, $container['network_names'])); @endphp
                @if (! empty($others))
                    <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-end">
                        <x-forms.select label="Connect to network" canGate="update" :canResource="$resource"
                            wire:model="selectedNetwork.{{ $container['name'] }}">
                            @foreach ($others as $name)
                                <option value="{{ $name }}">{{ $name }}</option>
                            @endforeach
                        </x-forms.select>
                        <x-forms.button canGate="update" :canResource="$resource"
                            wire:click="connect('{{ $container['name'] }}')">
                            Connect
                        </x-forms.button>
                    </div>
                @endif
                <p class="mt-3 text-xs text-neutral-500 dark:text-fg-dim">
                    Connecting or disconnecting here changes the running container only; a redeploy recreates it
                    with the networks configured in its settings.
                </p>
            </x-application.settings-section>
        @endforeach
    </div>
</div>
