<div>
    <x-modal yesOrNo modalId="deleteDestination" modalTitle="Delete Destination">
        <x-slot:modalBody>
            <p>This destination will be deleted. It is not reversible. <br>Please think again.</p>
        </x-slot:modalBody>
    </x-modal>
    <form class="flex flex-col">
        <div class="flex items-center gap-2">
            <h1>Destination</h1>
            <x-forms.button wire:click.prevent='submit' type="submit">
                Save
            </x-forms.button>
            @if ($destination->network !== 'coolify')
                <x-forms.button isError isModal modalId="deleteDestination">
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
