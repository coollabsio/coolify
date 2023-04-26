<div>
    @forelse ($servers as $server)
        <button @if ($chosenServer == $server->id) class="bg-blue-500" @endif
            wire:click="chooseServer({{ $server->id }})">{{ $server->name }}</button>
    @empty
        No servers
    @endforelse
    @isset($chosenServer)
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
    @endisset

    @isset($chosenDestination)
        <form wire:submit.prevent='submit'>
            <x-form-input id="public_repository_url" label="Repository URL" />
            <x-form-input type="checkbox" id="is_static" label="Static Site?" />
            <x-form-input type="number" id="port" label="Port" />
            <button type="submit">
                Submit
            </button>
        </form>
    @endisset

</div>
