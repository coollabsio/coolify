<x-layout>
    <h1>Server</h1>
    <livewire:server.form :server_id="$server->id" />
    <h2>Private Key <a
            href="{{ route('server.private-key', ['server_uuid' => $server->uuid]) }}"><button>Change</button></a>
    </h2>
    <p>{{ $server->privateKey->name }}</p>
    <h2>Destinations <a href="{{ route('destination.new', ['server_id' => $server->id]) }}"><button>New</button></a></h2>
    @if ($server->standaloneDockers)
        @foreach ($server->standaloneDockers as $docker)
            <p>Network: {{ data_get($docker, 'network') }}</p>
        @endforeach
    @endif
</x-layout>
