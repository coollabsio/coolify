<div>
    @if ($server->isFunctional())
        <div class="flex items-end gap-2">
            <h2>Destinations</h2>
            <x-modal-input buttonTitle="+ Add" title="New Destination">
                <livewire:destination.new.docker :server_id="$server->id" />
            </x-modal-input>
            <x-forms.button wire:click='scan'>Scan Destinations</x-forms.button>
        </div>
        <div class="pt-2 pb-6 ">Destinations are used to segregate resources by network.</div>
        <div class="flex gap-2 ">
            Available for using:
            @forelse ($server->standaloneDockers as $docker)
                <a href="{{ route('destination.show', ['destination_uuid' => data_get($docker, 'uuid')]) }}">
                    <button class="dark:text-white btn-link">{{ data_get($docker, 'network') }} </button>
                </a>
            @empty
            @endforelse
            @forelse ($server->swarmDockers as $docker)
                <a href="{{ route('destination.show', ['destination_uuid' => data_get($docker, 'uuid')]) }}">
                    <button class="dark:text-white btn-link">{{ data_get($docker, 'network') }} </button>
                </a>
            @empty
            @endforelse
        </div>
        <div class="pt-2">
            @if (count($networks) > 0)
                <h3 class="pb-4">Found Destinations</h3>
            @endif
            <div class="flex flex-wrap gap-2 ">
                @foreach ($networks as $network)
                    <div class="min-w-fit">
                        <x-forms.button wire:click="add('{{ data_get($network, 'Name') }}')">Add
                            {{ data_get($network, 'Name') }}</x-forms.button>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div>Server is not validated. Validate first.</div>
    @endif
</div>
