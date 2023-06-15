<x-layout>
    <x-server.navbar :server="$server" />
    <div class="flex items-end gap-2">
        <h1>Destinations</h1>
        <a href="{{ route('destination.new', ['server_id' => $server->id]) }}">
            <x-forms.button>Add a new destination</x-forms.button>
        </a>
    </div>
    <div class="pt-2 pb-6 text-sm">Destinations are used to segregate resources by network.</div>
    <div class="flex gap-2 text-sm">
        Docker Networks available on the server:
        @forelse ($server->standaloneDockers as $docker)
            <a href="{{ route('destination.show', ['destination_uuid' => data_get($docker, 'uuid')]) }}">
                <button class="text-white btn-link">{{ data_get($docker, 'network') }} </button>
            </a>
        @empty
            <div class="text-sm">No destinations added</div>
        @endforelse
    </div>
</x-layout>
