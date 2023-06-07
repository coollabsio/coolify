<div x-data="{ deleteServer: false }">
    <x-naked-modal show="deleteServer" message='Are you sure you would like to delete this server?' />
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex gap-2">
            <h2>General</h2>
            <x-forms.button type="submit">Save</x-forms.button>
            @if ($server_id !== 0)
                <x-forms.button x-on:click.prevent="deleteServer = true">
                    Delete
                </x-forms.button>
            @endif
            @if (!$server->settings->is_validated)
                <div class="w-full">
                    <x-forms.button isHighlighted wire:click.prevent='validateServer'>
                        Validate Server
                    </x-forms.button>
                </div>
            @endif
        </div>
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-full">
                @if ($server->id === 0)
                    <x-forms.input id="server.name" label="Name" readonly required />
                    <x-forms.input id="server.description" label="Description" readonly />
                @else
                    <x-forms.input id="server.name" label="Name" required />
                    <x-forms.input id="server.description" label="Description" />
                @endif

                {{-- <x-forms.checkbox disabled type="checkbox" id="server.settings.is_part_of_swarm"
                    label="Is it part of a Swarm cluster?" /> --}}
            </div>
            <div class="flex flex-col w-full">
                @if ($server->id === 0)
                    <x-forms.input id="server.ip" label="IP Address" readonly required />
                    <x-forms.input id="server.user" label="User" readonly required />
                    <x-forms.input type="number" id="server.port" label="Port" readonly required />
                @else
                    <x-forms.input id="server.ip" label="IP Address" readonly required />
                    <div class="flex gap-2">
                        <x-forms.input id="server.user" label="User" required />
                        <x-forms.input type="number" id="server.port" label="Port" required />
                    </div>
                @endif
            </div>
        </div>

        @if ($server->settings->is_validated)
            <h3 class="pt-8 pb-4">Quick Actions</h3>
            <div class="flex items-center gap-2">
                <x-forms.button wire:click.prevent='validateServer'>
                    Check Connection
                </x-forms.button>
                {{-- <x-forms.button wire:click.prevent='installDocker'>Install Docker</x-forms.button> --}}
            </div>
        @endif
        @isset($uptime)
            <h3>Server Info</h3>
            <div class="pt-3 text-sm">
                <p>Uptime: {{ $uptime }}</p>
                @isset($dockerVersion)
                    <p>Docker Engine {{ $dockerVersion }}</p>
                @endisset
                @isset($dockerComposeVersion)
                    <p>{{ $dockerComposeVersion }}</p>
                @endisset
            </div>
        @endisset
    </form>
    <div class="flex items-center gap-2 py-4">
        <h3>Private Key</h3>
        <a href="{{ route('server.private-key', ['server_uuid' => $server->uuid]) }}">
            <x-forms.button>
                @if (data_get($server, 'privateKey.uuid'))
                    Change
                @else
                    Add
                @endif
            </x-forms.button>
        </a>
    </div>
    @if (data_get($server, 'privateKey.uuid'))
        <a href="{{ route('private-key.show', ['private_key_uuid' => data_get($server, 'privateKey.uuid')]) }}">
            <button class="text-white btn-link">{{ data_get($server, 'privateKey.name') }}</button>
        </a>
    @else
        <div class="text-sm">No private key attached.</div>
    @endif
    <div class="flex items-center gap-2 py-4">
        <h3>Destinations</h3>
        <a href="{{ route('destination.new', ['server_id' => $server->id]) }}">
            <x-forms.button>Add</x-forms.button>
        </a>
    </div>
    <div>
        @forelse ($server->standaloneDockers as $docker)
            <a href="{{ route('destination.show', ['destination_uuid' => data_get($docker, 'uuid')]) }}">
                <button class="text-white btn-link">{{ data_get($docker, 'network') }}</button>
            </a>
        @empty
            <div class="text-sm">No destinations added</div>
        @endforelse
    </div>
</div>
