<div>
    @if ($servers->count() > 0)
        <h1>Choose a server</h1>
    @endif
    @forelse ($servers as $server)
        <button @if ($chosenServer == $server->id) class="bg-blue-500" @endif
            wire:click="chooseServer({{ $server }})">{{ $server->name }}</button>
    @empty
        No servers found.
        <p>Did you forget to add a destination on the server?</p>
    @endforelse

    @isset($chosenServer)
        @if ($standalone_docker->count() > 0 || $swarm_docker->count() > 0)
            <h1>Choose a destination</h1>
            <div>
                @foreach ($standalone_docker as $standalone)
                    <button @if ($chosenDestination?->uuid == $standalone->uuid) class="bg-blue-500" @endif
                        wire:click="setDestination('{{ $standalone->uuid }}','StandaloneDocker')">{{ $standalone->network }}</button>
                @endforeach
                @foreach ($swarm_docker as $standalone)
                    <button @if ($chosenDestination?->uuid == $standalone->uuid) class="bg-blue-500" @endif
                        wire:click="setDestination('{{ $standalone->uuid }}','SwarmDocker')">{{ $standalone->uuid }}</button>
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
        <form class="flex flex-col gap-2 w-96" wire:submit.prevent='submit'>
            <x-form-input class="w-96" id="public_repository_url" label="Repository URL" />
            <x-form-input instantSave type="checkbox" id="is_static" label="Static Site?" />
            @if ($is_static)
                <x-form-input id="publish_directory" label="Publish Directory" />
            @else
                <x-form-input type="number" id="port" label="Port" :disabled="$is_static" />
            @endif
            <button type="submit">
                Submit
            </button>
        </form>
    @endisset

</div>
