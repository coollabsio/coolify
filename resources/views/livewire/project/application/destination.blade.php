<div>
    <h3>Destination</h3>
    <p>Server Name: {{ data_get($destination, 'server.name') }}</p>
    @if (data_get($destination, 'server.description'))
        <p>Description: {{ data_get($destination, 'server.description') }}</p>
    @endif
    <p>Docker Network: {{ $destination->network }}</p>
</div>
