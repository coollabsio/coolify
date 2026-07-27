<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Destinations | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-8 grid w-full max-w-[1180px] min-w-0 gap-8 xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar :server="$server" activeMenu="destinations" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            @if ($server->isFunctional())
                <x-application.settings-section id="server-destinations-section" title="Destinations"
                    helper="Docker networks used to isolate and connect resources on this server." flush>
                    <x-slot:actions>
                        <div class="flex items-center gap-2">
                            <x-forms.button canGate="update" :canResource="$server" wire:click="scan">
                                <x-reicon name="refresh" class="size-3.5" />
                                Scan networks
                            </x-forms.button>
                            @can('update', $server)
                                <x-modal-input buttonTitle="+ Add" title="New Destination">
                                    <livewire:destination.new.docker :server_id="$server->id" />
                                </x-modal-input>
                            @endcan
                        </div>
                    </x-slot:actions>

                    @forelse ($server->standaloneDockers->concat($server->swarmDockers) as $destination)
                        <a href="{{ route('destination.show', ['destination_uuid' => data_get($destination, 'uuid')]) }}"
                            {{ wireNavigate() }}
                            class="flex items-center gap-4 border-b border-neutral-200 px-4 py-3 transition-colors last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.08] dark:hover:bg-white/[0.03]">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                                <x-reicon name="destinations" class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-neutral-950 dark:text-fg">
                                    {{ data_get($destination, 'network') }}
                                </p>
                                <p class="mt-0.5 text-xs text-neutral-500 dark:text-fg-dim">
                                    {{ $server->swarmDockers->contains('id', data_get($destination, 'id')) ? 'Docker Swarm' : 'Standalone Docker' }}
                                </p>
                            </div>
                            <x-reicon name="arrow-right" class="size-4 text-neutral-400" />
                        </a>
                    @empty
                        <x-empty size="sm" title="No destinations"
                            description="Add a destination or scan the server for existing Docker networks.">
                            <x-slot:icon>
                                <x-reicon name="destinations" class="size-8" />
                            </x-slot:icon>
                        </x-empty>
                    @endforelse
                </x-application.settings-section>

                @if ($networks->count() > 0)
                    <x-application.settings-section id="server-found-networks-section" title="Discovered networks"
                        helper="Networks found on the server that are not registered as Coolify destinations."
                        flush>
                        @foreach ($networks as $network)
                            <div
                                class="flex items-center justify-between gap-4 border-b border-neutral-200 px-4 py-3 last:border-b-0 dark:border-white/[0.08]">
                                <div>
                                    <p class="text-sm font-medium text-neutral-950 dark:text-fg">
                                        {{ data_get($network, 'Name') }}
                                    </p>
                                    <p class="mt-0.5 text-xs text-neutral-500 dark:text-fg-dim">Docker network</p>
                                </div>
                                <x-forms.button canGate="update" :canResource="$server"
                                    wire:click="add('{{ data_get($network, 'Name') }}')">
                                    Add destination
                                </x-forms.button>
                            </div>
                        @endforeach
                    </x-application.settings-section>
                @endif
            @else
                <x-application.settings-section title="Destinations"
                    helper="Docker networks used to isolate and connect resources on this server.">
                    <x-empty size="sm" title="Server validation required"
                        description="Validate this server before managing its destinations.">
                        <x-slot:icon>
                            <x-reicon name="destinations" class="size-8" />
                        </x-slot:icon>
                    </x-empty>
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>
