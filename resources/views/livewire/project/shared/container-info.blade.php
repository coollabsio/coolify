<div>
    <div class="flex flex-col gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h2>Container Info</h2>
            </div>
            <div class="pb-4">Read-only Docker container details for this resource.</div>
        </div>

        @if ($containers->isEmpty())
            <div class="alert alert-warning">
                No Docker containers were found for this resource on a functional non-Swarm server.
            </div>
        @else
            <div class="flex flex-col gap-2 sm:w-96">
                <x-forms.select label="Container" id="selectedContainerKey" wire:model.live="selectedContainerKey">
                    <option value="">Select a container</option>
                    @foreach ($containers as $container)
                        <option value="{{ data_get($container, 'key') }}">
                            {{ data_get($container, 'container.Names') }}
                            ({{ data_get($container, 'server.name') }})
                        </option>
                    @endforeach
                </x-forms.select>
                <div>
                    <x-forms.button wire:click="refreshInfo">Refresh</x-forms.button>
                </div>
            </div>

            @if ($error)
                <div class="alert alert-warning">{{ $error }}</div>
            @endif

            @if (filled($containerInfo))
                <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <div class="box-without-bg">
                        <h3>General</h3>
                        <div class="grid grid-cols-1 gap-3 pt-4">
                            <div>
                                <div class="text-xs uppercase text-neutral-500">Container ID</div>
                                <x-forms.copy-button :text="$containerInfo['id'] ?: 'n/a'" />
                            </div>
                            <div>
                                <div class="text-xs uppercase text-neutral-500">Container Name</div>
                                <x-forms.copy-button :text="$containerInfo['name'] ?: 'n/a'" />
                            </div>
                            <div>
                                <div class="text-xs uppercase text-neutral-500">Image</div>
                                <x-forms.copy-button :text="$containerInfo['image'] ?: 'n/a'" />
                            </div>
                            <div>
                                <div class="text-xs uppercase text-neutral-500">Image ID</div>
                                <x-forms.copy-button :text="$containerInfo['image_id'] ?: 'n/a'" />
                            </div>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <div class="text-xs uppercase text-neutral-500">Status</div>
                                    <div class="font-mono text-sm">{{ $containerInfo['status'] ?? 'n/a' }}</div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase text-neutral-500">Restart Count</div>
                                    <div class="font-mono text-sm">{{ $containerInfo['restart_count'] ?? 'n/a' }}</div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase text-neutral-500">Created</div>
                                    <div class="font-mono text-sm break-all">{{ $containerInfo['created_at'] ?? 'n/a' }}</div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase text-neutral-500">Started</div>
                                    <div class="font-mono text-sm break-all">{{ $containerInfo['started_at'] ?? 'n/a' }}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="box-without-bg">
                        <h3>Networks</h3>
                        <div class="pt-4">
                            @if (empty($containerInfo['networks']))
                                <div class="text-sm text-neutral-500">No network data returned by Docker inspect.</div>
                            @else
                                <div class="overflow-x-auto">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Network</th>
                                                <th>IPv4</th>
                                                <th>IPv6</th>
                                                <th>MAC</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($containerInfo['networks'] as $network)
                                                <tr>
                                                    <td class="font-mono text-xs">{{ $network['name'] }}</td>
                                                    <td class="font-mono text-xs">{{ $network['ipv4'] ?: 'n/a' }}</td>
                                                    <td class="font-mono text-xs">{{ $network['ipv6'] ?: 'n/a' }}</td>
                                                    <td class="font-mono text-xs">{{ $network['mac_address'] ?: 'n/a' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </div>
</div>
