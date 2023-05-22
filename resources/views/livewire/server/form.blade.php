<div x-data="{ deleteServer: false }">
    <x-naked-modal show="deleteServer" message='Are you sure you would like to delete this server?' />
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex gap-2">
            <h2>General</h2>
            <x-inputs.button type="submit">Save</x-inputs.button>
            @if ($server_id !== 0)
                <x-inputs.button isWarning x-on:click.prevent="deleteServer = true">
                    Delete
                </x-inputs.button>
            @endif
        </div>
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-96">
                <x-inputs.input id="server.name" label="Name" required />
                <x-inputs.input id="server.description" label="Description" />
                {{-- <x-inputs.checkbox disabled type="checkbox" id="server.settings.is_part_of_swarm"
                    label="Is it part of a Swarm cluster?" /> --}}
            </div>
            <div class="flex flex-col w-96">
                @if ($server->id === 0)
                    <x-inputs.input id="server.ip" label="IP Address" readonly />
                    <x-inputs.input id="server.user" label="User" readonly />
                    <x-inputs.input type="number" id="server.port" label="Port" readonly />
                @else
                    <x-inputs.input id="server.ip" label="IP Address" required readonly />
                    <x-inputs.input id="server.user" label="User" required />
                    <x-inputs.input type="number" id="server.port" label="Port" required />
                @endif
            </div>
        </div>

        <div class="flex items-center gap-2">
            <x-inputs.button isHighlighted wire:click.prevent='validateServer'>
                @if ($server->settings->is_validated)
                    Check Connection
                @else
                    Validate Server
                @endif
            </x-inputs.button>

            {{-- <x-inputs.button  wire:click.prevent='installDocker'>Install Docker</x-inputs.button> --}}

        </div>
        <div class="pt-3">
            @isset($uptime)
                <p>Uptime: {{ $uptime }}</p>
            @endisset
            @isset($dockerVersion)
                <p>Docker Engine {{ $dockerVersion }}</p>
            @endisset
            @isset($dockerComposeVersion)
                <p>{{ $dockerComposeVersion }}</p>
            @endisset
        </div>
    </form>
    <div class="flex items-center gap-2 py-4">
        <div class="font-bold">Private Key</div>
        <a class="px-2"
            href="{{ route('private-key.show', ['private_key_uuid' => data_get($server, 'privateKey.uuid')]) }}">
            {{ data_get($server, 'privateKey.uuid') }}
        </a>
        <a href="{{ route('server.private-key', ['server_uuid' => $server->uuid]) }}">
            <x-inputs.button>Change</x-inputs.button>
        </a>
    </div>
    <div class="flex items-center gap-2 py-4">
        <div class="font-bold">Destinations</div>
        <div>
            @foreach ($server->standaloneDockers as $docker)
                <a class="px-2"
                    href="{{ route('destination.show', ['destination_uuid' => data_get($docker, 'uuid')]) }}">
                    {{ data_get($docker, 'network') }}
                </a>
            @endforeach
        </div>
        <a href="{{ route('destination.new', ['server_id' => $server->id]) }}">
            <x-inputs.button>Add</x-inputs.button>
        </a>
    </div>
</div>
