<div>
    <form class="flex flex-col">
        <div class="flex items-center gap-2">
            <h1>Destination</h1>
            <x-forms.button wire:click.prevent='submit' type="submit">
                Save
            </x-forms.button>
            @if ($destination->network !== 'coolify')
                <x-modal-confirmation title="Confirm Destination Deletion?" buttonTitle="Delete Destination" isErrorButton
                    submitAction="delete" :actions="['This will delete the selected destination/network.']" confirmationText="{{ $destination->name }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the Destination Name below"
                    shortConfirmationLabel="Destination Name" :confirmWithPassword="false" step2ButtonText="Permanently Delete" />
            @endif
        </div>

        @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
            <div class="subtitle ">A Docker network in a non-swarm environment.</div>
        @else
            <div class="subtitle ">Your swarm docker network. WIP</div>
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
