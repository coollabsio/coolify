<x-layout>
    <h1>Destination</h1>
    <p>Name: {{ data_get($destination, 'name') }}</p>
    <p>Server:{{ data_get($destination, 'server.ip') }}:{{ data_get($destination, 'server.port') }} </p>
    @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
        <p>Network: {{ data_get($destination, 'network') }}</p>
    @endif
    @if ($destination->getMorphClass() === 'App\Models\SwarmDocker')
        <p>Uuid: {{ data_get($destination, 'uuid') }}</p>
    @endif
</x-layout>
