<div>
    @if ($servers->count() > 0)
        <h1>Choose a server</h1>
    @endif
    @forelse ($servers as $server)
        @if ($chosenServer && $chosenServer['id'] === $server->id)
            <x-inputs.button class="bg-blue-500" wire:click="chooseServer({{ $server }})">{{ $server->name }}
            </x-inputs.button>
        @else
            <x-inputs.button wire:click="chooseServer({{ $server }})">{{ $server->name }}</x-inputs.button>
        @endif
    @empty
        No servers found.
        <p>Did you forget to add a destination on the server?</p>
    @endforelse

    @isset($chosenServer)
        @if ($standalone_docker->count() > 0 || $swarm_docker->count() > 0)
            <h1>Choose a destination</h1>
            <div>
                @foreach ($standalone_docker as $standalone)
                    @if ($chosenDestination?->uuid == $standalone->uuid)
                        <x-inputs.button class="bg-blue-500"
                            wire:click="setDestination('{{ $standalone->uuid }}','StandaloneDocker')">
                            {{ $standalone->network }}</x-inputs.button>
                    @else
                        <x-inputs.button wire:click="setDestination('{{ $standalone->uuid }}','StandaloneDocker')">
                            {{ $standalone->network }}</x-inputs.button>
                    @endif
                @endforeach
                @foreach ($swarm_docker as $standalone)
                    @if ($chosenDestination?->uuid == $standalone->uuid)
                        <x-inputs.button class="bg-blue-500"
                            wire:click="setDestination('{{ $standalone->uuid }}','SwarmDocker')">
                            {{ $standalone->network }}</x-inputs.button>
                    @else
                        <x-inputs.button wire:click="setDestination('{{ $standalone->uuid }}','SwarmDocker')">
                            {{ $standalone->uuid }}</x-inputs.button>
                    @endif
                @endforeach
            </div>
            <div>
                <a href="{{ route('destination.new', ['server_id' => $chosenServer['id']]) }}">Add
                    a new
                    destination</a>
            </div>
        @else
            <h1>No destinations found on this server.</h1>
            <a href="{{ route('destination.new', ['server_id' => $chosenServer['id']]) }}">Add
                a
                destination</a>
        @endif

    @endisset
    @isset($chosenDestination)
        <h1>Choose a repository</h1>
        <form wire:submit.prevent='submit'>
            <div class="flex items-end gap-2 pb-2">
                <x-inputs.input class="w-96" id="repository_url" label="Repository URL" />
                @if ($is_static)
                    <x-inputs.input id="publish_directory" label="Publish Directory" />
                @else
                    <x-inputs.input type="number" id="port" label="Port" :readonly="$is_static" />
                @endif
                <x-inputs.input instantSave type="checkbox" id="is_static" label="Static Site?" />
            </div>
            <x-inputs.button type="submit">
                Submit
            </x-inputs.button>
        </form>
        <div>
            <h1>Select a private key</h1>
            @foreach ($private_keys as $key)
                @if ($private_key_id == $key->id)
                    <x-inputs.button class="bg-blue-500" wire:click.defer="setPrivateKey('{{ $key->id }}')">
                        {{ $key->name }}</x-inputs.button>
                @else
                    <x-inputs.button wire:click.defer="setPrivateKey('{{ $key->id }}')">{{ $key->name }}
                    </x-inputs.button>
                @endif
            @endforeach
        </div>
    @endisset
</div>
