<div class="py-8">
    <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h3>Container Information</h3>
            <div class="pb-2">Inspect running and stopped containers, exposed ports, and Docker networks for this resource.</div>
        </div>
        <x-forms.button wire:click="loadContainers" wire:loading.attr="disabled" wire:target="loadContainers">
            Refresh
        </x-forms.button>
    </div>

    @if ($error)
        <x-callout type="warning" title="Container information unavailable">
            {{ $error }}
        </x-callout>
    @elseif (count($containers) === 0)
        <x-callout type="info" title="No containers found">
            Deploy this resource to see container and network information.
        </x-callout>
    @else
        <div class="overflow-x-auto">
            <div class="inline-block min-w-full">
                <div class="overflow-hidden">
                    <table class="min-w-full">
                        <thead>
                            <tr>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Container</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Image</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Status</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Ports</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Networks</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($containers as $container)
                                <tr>
                                    <td class="px-5 py-4 text-sm align-top dark:text-white">
                                        <div class="font-bold">{{ $container['name'] ?: $container['short_id'] }}</div>
                                        <div class="text-xs text-neutral-600 dark:text-neutral-400">{{ $container['short_id'] }}</div>
                                        @if ($container['compose_service'])
                                            <div class="text-xs text-neutral-600 dark:text-neutral-400">{{ $container['compose_service'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-sm align-top dark:text-white">
                                        {{ $container['image'] ?: '-' }}
                                    </td>
                                    <td class="px-5 py-4 text-sm align-top whitespace-nowrap dark:text-white">
                                        <div>{{ $container['status'] }}</div>
                                        <div class="text-xs text-neutral-600 dark:text-neutral-400">
                                            Restarts: {{ $container['restart_count'] }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-sm align-top dark:text-white">
                                        @forelse ($container['ports'] as $port)
                                            <div>{{ $port }}</div>
                                        @empty
                                            -
                                        @endforelse
                                    </td>
                                    <td class="px-5 py-4 text-sm align-top dark:text-white">
                                        @forelse ($container['networks'] as $network)
                                            <div class="pb-2">
                                                <div class="font-medium">{{ $network['name'] }}</div>
                                                <div class="text-xs text-neutral-600 dark:text-neutral-400">
                                                    IP: {{ $network['ip_address'] ?: '-' }}
                                                    @if ($network['gateway'])
                                                        | Gateway: {{ $network['gateway'] }}
                                                    @endif
                                                </div>
                                            </div>
                                        @empty
                                            -
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
