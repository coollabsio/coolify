<div>
    <x-slot:title>
        Destinations | Coolify
    </x-slot>
    <div class="flex flex-col gap-4">
        <section class="flex flex-col gap-3">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1>Destinations</h1>
                    @if ($selectedServer)
                        <x-forms.button canGate="update" :canResource="$selectedServer"
                            wire:click="$dispatch('refreshDestinationNetworks')"
                            :disabled="!$selectedServer->isFunctional()">
                            Refresh
                        </x-forms.button>
                    @endif
                    @if ($servers->isNotEmpty())
                        <x-modal-input buttonTitle="+ Add" title="Add Destination"
                            :closeOutside="false" closeEvent="destination-created">
                            <form wire:submit="createDestination" class="flex flex-col gap-4">
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <x-forms.input id="createDisplayName" label="Display name" required />
                                    <x-forms.input id="createDockerNetworkName" label="Docker network name" required />
                                    <div class="sm:col-span-2">
                                        <x-forms.select id="createServerUuid" label="Server" required>
                                            <option value="">Select a server</option>
                                            @foreach ($serverOptions as $serverOption)
                                                <option value="{{ $serverOption['uuid'] }}">{{ $serverOption['name'] }}</option>
                                            @endforeach
                                        </x-forms.select>
                                    </div>
                                    <x-forms.input id="createSubnet" label="Subnet" placeholder="Automatic" />
                                    <x-forms.input id="createGateway" label="Gateway" placeholder="Automatic" />
                                    <div>
                                        <x-forms.checkbox id="createInternal" label="Internal network" />
                                        <div class="mt-1 text-xs text-neutral-500">Isolates attached workloads from external networks.</div>
                                    </div>
                                    <div>
                                        <x-forms.checkbox id="createProxyAccess" label="Allow Coolify proxy access"
                                            :disabled="$createInternal" />
                                        <div class="mt-1 text-xs text-neutral-500">Connects proxy only when explicitly enabled and safe.</div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap justify-end gap-2">
                                    <x-forms.button type="submit" isHighlighted wire:loading.attr="disabled"
                                        wire:target="createDestination">
                                        Create Destination
                                    </x-forms.button>
                                </div>
                            </form>
                        </x-modal-input>
                    @endif
                </div>
                <div class="subtitle">Network endpoints to deploy your resources.</div>
            </div>

            @if ($servers->isEmpty())
                <div class="box-without-bg text-sm text-neutral-500">No servers available.</div>
            @elseif ($destinations->isEmpty())
                <div class="box-without-bg text-sm text-neutral-500">
                    No Destinations configured yet. Add one or promote an eligible network below.
                </div>
            @else
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    @foreach ($destinations as $destination)
                        <a wire:key="destination-summary-{{ $destination['uuid'] }}" {{ wireNavigate() }}
                            href="{{ route('destination.show', ['destination_uuid' => $destination['uuid']]) }}"
                            class="group flex min-w-0 flex-col rounded-sm border border-neutral-200 bg-white px-8 py-4 transition-colors hover:border-coollabs dark:border-coolgray-200 dark:bg-coolgray-100">
                            <div class="truncate font-bold group-hover:text-coollabs">{{ $destination['name'] }}</div>
                            <div class="truncate text-sm font-semibold text-neutral-500">Server: {{ $destination['server_name'] }}</div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        @if ($selectedServer)
            <livewire:destination.docker-networks :server_uuid="$selectedServer->uuid" :server_options="$serverOptions"
                :key="'docker-networks-'.$selectedServer->uuid.'-'.$inventoryVersion" />
        @endif
    </div>
</div>
