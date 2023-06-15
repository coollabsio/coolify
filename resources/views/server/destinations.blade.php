<x-layout>
    <x-server.navbar :server="$server" />
    <div class="flex items-end gap-2">
        <h1>Destinations</h1>
        <a href="{{ route('destination.new', ['server_id' => $server->id]) }}">
            <x-forms.button>Add a new destination</x-forms.button>
        </a>
    </div>
    <div class="pt-2 pb-8 text-sm">Docker Networks available on the server</div>
    <div class="pb-10 text-sm">
        @forelse ($server->standaloneDockers as $docker)
            <a href="{{ route('destination.show', ['destination_uuid' => data_get($docker, 'uuid')]) }}">
                <x-forms.button>{{ data_get($docker, 'network') }} </x-forms.button>
            </a>
        @empty
            <div class="text-sm">No destinations added</div>
        @endforelse
    </div>
</x-layout>
