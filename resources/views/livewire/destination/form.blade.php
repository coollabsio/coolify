<div x-data="{ deleteDestination: false }">
    <x-naked-modal show="deleteDestination" title="Delete Destination"
        message='This destination will be deleted. It is not reversible. <br>Please think again.' />
    <form class="flex flex-col">
        <div class="flex items-center gap-2">
            <h1>Destination</h1>
            <x-forms.button wire:click.prevent='submit' type="submit">
                Save
            </x-forms.button>
            @if ($destination->server->id === 0 && $destination->network !== 'coolify')
                <x-forms.button x-on:click.prevent="deleteDestination = true">
                    Delete
                </x-forms.button>
            @endif
        </div>

        @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
            <div class="pt-2 pb-10 ">A Docker network in a non-swarm environment</div>
        @else
            <div class="pt-2 pb-10 ">Your swarm docker network. WIP</div>
        @endif
        <div class="flex gap-2">
            <x-forms.input id="destination.name" label="Name" />
            <x-forms.input id="destination.server.ip" label="Server IP" readonly />
            @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
                <x-forms.input id="destination.network" label="Docker Network" readonly />
            @endif
        </div>
    </form>
</div>
