<div>
    @if ($server->settings->is_usable)
        <div class="flex items-end gap-2">
            <h2>Destinations</h2>
            <a href="{{ route('destination.new', ['server_id' => $server->id]) }}">
                <x-forms.button>Add a new destination</x-forms.button>
            </a>
            <x-forms.button wire:click='scan'>Scan destinations on the server</x-forms.button>
        </div>
        <div class="pt-2 pb-6 ">Destinations are used to segregate resources by network.</div>
        <div class="flex gap-2 ">
            Available for using:
            @forelse ($server->standaloneDockers as $docker)
                <a href="{{ route('destination.show', ['destination_uuid' => data_get($docker, 'uuid')]) }}">
                    <button class="text-white btn-link">{{ data_get($docker, 'network') }} </button>
                </a>
            @empty
                <div class="">N/A</div>
            @endforelse
        </div>
        <div class="grid gap-2 pt-2">
            @if (count($networks) > 0)
                <h4>Found Destinations</h4>
            @endif
            @foreach ($networks as $network)
                <a
                    href="{{ route('destination.new', ['server_id' => $server->id, 'network_name' => data_get($network, 'Name')]) }}">
                    <x-forms.button>Add<span class="text-warning">{{ data_get($network, 'Name') }}</span>
                    </x-forms.button>
                </a>
            @endforeach
        </div>
    @else
        <div>Server is not validated. Validate first.</div>
    @endif
</div>
