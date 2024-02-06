<div>
    <h2>Server</h2>
    <div class="">Server related configurations.</div>
    <div class="grid grid-cols-2 gap-4 py-4">
        {{-- <a class="box"
            href="{{ route('server.show', ['server_uuid' => data_get($resource, 'destination.server.uuid')]) }}">On
            server <span class="px-1 text-warning">{{ data_get($resource, 'destination.server.name') }}</span>
            in <span class="px-1 text-warning"> {{ data_get($resource, 'destination.network') }} </span> network</a>
        @if (count($additional_destinations) > 0)
            @foreach ($additional_destinations as $destination)
                <a class="box"
                    href="{{ route('server.show', ['server_uuid' => data_get($destination, 'server.uuid')]) }}">On server
                    <span class="px-1 text-warning">{{ data_get($destination, 'server.name') }}</span> in <span
                        class="px-1 text-warning"> {{ data_get($destination, 'network') }} </span> network</a>
            @endforeach
        @endif --}}
        <div class="box"
            wire:click="removeServer('{{ data_get($resource, 'destination.id') }}','{{ data_get($resource, 'destination.server.id') }}')">
            On
            server <span class="px-1 text-warning">{{ data_get($resource, 'destination.server.name') }}</span>
            in <span class="px-1 text-warning"> {{ data_get($resource, 'destination.network') }} </span> network</div>
        @if (count($resource->additional_networks) > 0)
            @foreach ($resource->additional_networks as $destination)
                <div class="box"
                    wire:click="removeServer('{{ data_get($destination, 'id') }}','{{ data_get($destination, 'server.id') }}')">
                    On
                    server
                    <span class="px-1 text-warning">{{ data_get($destination, 'server.name') }}</span> in <span
                        class="px-1 text-warning"> {{ data_get($destination, 'network') }} </span> network
                </div>
            @endforeach
        @endif
    </div>
    <h4>Attach to a Server</h4>
    @if (count($networks) > 0)
        <div class="grid grid-cols-2 gap-4">
            @foreach ($networks as $network)
                <div wire:click="addServer('{{ $network->id }}','{{ data_get($network, 'server.id') }}')"
                    class="box">
                    {{ data_get($network, 'server.name') }}
                    {{ $network->name }}
                </div>
            @endforeach
        </div>
    @else
        <div class="text-neutral-500">No additional servers available to attach.</div>
    @endif
</div>
