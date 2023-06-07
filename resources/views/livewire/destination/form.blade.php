<div x-data="{ deleteDestination: false }">
    <x-naked-modal show="deleteDestination" message='Are you sure you would like to delete this destination?' />
    <form class="flex flex-col gap-4" wire:submit.prevent='submit'>
        <x-forms.input id="destination.name" label="Name" />
        <x-forms.input id="destination.server.ip" label="Server IP" readonly />
        @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
            <x-forms.input id="destination.network" label="Docker Network" readonly />
        @endif
        <div>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            <x-forms.button x-on:click.prevent="deleteDestination = true">
                Delete
            </x-forms.button>
        </div>
    </form>
</div>
