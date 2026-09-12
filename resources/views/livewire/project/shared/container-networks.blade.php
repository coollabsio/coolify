@if ($server && $containerName)
    <x-application.settings-section id="container-networks-section" title="Networks"
        helper="Networks the container is currently connected to. Connecting or disconnecting is applied to the container immediately, but it is not persisted and will be reset after a restart or redeploy unless the network is part of the resource configuration.">
        @if (! $isSupported)
            <x-callout type="info" title="{{ $isSwarm ? 'Docker Swarm' : 'Server unavailable' }}" class="mb-0">
                @if ($isSwarm)
                    Managing container networks from the UI is not supported for Swarm mode yet.
                @else
                    The server is currently unreachable, so the container networks cannot be loaded.
                @endif
            </x-callout>
        @else
            <div class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                        @if ($connectedNetworks->isEmpty())
                            This container is not connected to any network.
                        @elseif ($connectedNetworks->count() === 1)
                            This container is connected to 1 network.
                        @else
                            This container is connected to {{ $connectedNetworks->count() }} networks.
                        @endif
                    </p>
                    <div class="flex items-center gap-2">
                        <x-loading wire:loading wire:target="connectNetwork, disconnectNetwork, refreshNetworks" />
                        <x-forms.button wire:click="refreshNetworks">Refresh</x-forms.button>
                    </div>
                </div>
                @if ($connectedNetworks->isNotEmpty())
                    <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                        @foreach ($connectedNetworks as $network)
                            <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                                wire:key="connected-network-{{ $network }}">
                                <div class="flex min-w-0 items-center gap-3">
                                    <div
                                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                                        <x-reicon name="network" class="size-[18px]" />
                                    </div>
                                    <code
                                        class="truncate rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-xs text-neutral-700 dark:bg-white/[0.05] dark:text-fg-dim">{{ $network }}</code>
                                </div>
                                <x-forms.button canGate="update" :canResource="$resource"
                                    wire:click="disconnectNetwork('{{ $network }}')">
                                    Disconnect
                                </x-forms.button>
                            </div>
                        @endforeach
                    </div>
                @else
                    <x-empty size="sm" title="No networks connected"
                        description="Connect this container to a network to communicate with other containers on it."
                        icon-name="network" />
                @endif
                @if ($availableNetworks->isNotEmpty())
                    <div class="flex flex-col gap-3 border-t border-neutral-200 pt-4 sm:flex-row sm:items-end sm:justify-between dark:border-white/[0.08]">
                        <div class="w-full sm:max-w-xs">
                            <x-forms.listbox id="selectedNetwork" label="Connect to a network" canGate="update"
                                :canResource="$resource"
                                :options="$availableNetworks->map(fn ($network) => ['value' => $network['name'], 'label' => $network['name']])->values()->all()" />
                        </div>
                        <x-forms.button canGate="update" :canResource="$resource" wire:click="connectNetwork">
                            Connect
                        </x-forms.button>
                    </div>
                @endif
            </div>
        @endif
    </x-application.settings-section>
@endif
