<div x-data="{ deleteDestination: false }">
    <x-naked-modal show="deleteDestination" message='Are you sure you would like to delete this destination?' />
    <form class="flex flex-col gap-4" wire:submit.prevent='submit'>
        <x-inputs.input id="destination.name" label="Name" />
        <x-inputs.input id="destination.server.ip" label="Server IP" readonly />
        @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
            <x-inputs.input id="destination.network" label="Docker Network" readonly />
        @endif
        <div>
            <x-inputs.button type="submit">
                Save
            </x-inputs.button>
            <x-inputs.button x-on:click.prevent="deleteDestination = true">
                Delete
            </x-inputs.button>
        </div>
    </form>
</div>
