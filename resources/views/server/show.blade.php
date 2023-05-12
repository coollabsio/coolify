<x-layout>
    <h1>Server</h1>
    <livewire:server.form :server_id="$server->id" />
    <h2>Private Key <a href="{{ route('server.private-key', ['server_uuid' => $server->uuid]) }}">
            <x-inputs.button>Change</x-inputs.button>
        </a>
    </h2>
    <p>{{ $server->privateKey->name }}</p>
    <h2>Destinations <a href="{{ route('destination.new', ['server_id' => $server->id]) }}">
            <x-inputs.button>New</x-inputs.button>
        </a></h2>
    @if ($server->standaloneDockers)
        @foreach ($server->standaloneDockers as $docker)
            <p>Network: {{ data_get($docker, 'network') }}</p>
        @endforeach
    @endif

    <livewire:server.proxy :server="$server"/>
</x-layout>
