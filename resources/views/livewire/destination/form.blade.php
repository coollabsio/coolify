<div x-data="{ deleteDestination: false }">
    <x-naked-modal show="deleteDestination" message='Are you sure you would like to delete this destination?' />
    <form class="flex flex-col">
        <div class="flex items-center gap-2">
            <h1>Destination</h1>
            <x-forms.button wire:click.prevent='submit' type="submit">
                Save
            </x-forms.button>
            @if ($destination->network !== 'coolify')
                <x-forms.button x-on:click.prevent="deleteDestination = true">
                    Delete
                </x-forms.button>
            @endif
        </div>

        @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
            <div class="pt-2 pb-10 text-sm">Your standalone docker network.</div>
        @else
            <div class="pt-2 pb-10 text-sm">Your swarm docker network. WIP</div>
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
