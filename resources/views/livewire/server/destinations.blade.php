<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Destinations | Coolify
    </x-slot>
    <x-server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="destinations" />
        <div class="w-full">
            @if ($server->isFunctional())
                <div class="flex items-end gap-2">
                    <h2>Destinations</h2>
                    <x-modal-input buttonTitle="+ Add" title="New Destination">
                        <livewire:destination.new.docker :server_id="$server->id" />
                    </x-modal-input>
                    <x-forms.button isHighlighted wire:click='scan'>Scan for Destinations</x-forms.button>
                </div>
                <div>Destinations are used to segregate resources by network.</div>
                <h4 class="pt-4 pb-2">Available Destinations</h4>
                <div class="flex gap-2">
                    @foreach ($server->standaloneDockers as $docker)
                        <a href="{{ route('destination.show', ['destination_uuid' => data_get($docker, 'uuid')]) }}">
                            <x-forms.button>{{ data_get($docker, 'network') }} </x-forms.button>
                        </a>
                    @endforeach
                    @foreach ($server->swarmDockers as $docker)
                        <a href="{{ route('destination.show', ['destination_uuid' => data_get($docker, 'uuid')]) }}">
                            <x-forms.button>{{ data_get($docker, 'network') }} </x-forms.button>
                        </a>
                    @endforeach
                </div>
                @if ($networks->count() > 0)
                    <div class="pt-2">
                        <h3 class="pb-4">Found Destinations</h3>
                        <div class="flex flex-wrap gap-2 ">
                            @foreach ($networks as $network)
                                <div class="min-w-fit">
                                    <x-forms.button wire:click="add('{{ data_get($network, 'Name') }}')">Add
                                        {{ data_get($network, 'Name') }}</x-forms.button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @else
                <div>Server is not validated. Validate first.</div>
            @endif
        </div>
    </div>
</div>
