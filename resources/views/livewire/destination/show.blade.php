<div>
    <form class="flex flex-col">
        <div class="form-section-title">
            <h1>Destination</h1>
            <div class="flex items-center gap-2">
                <x-forms.button canGate="update" :canResource="$destination" wire:click.prevent='submit'
                    type="submit">Save</x-forms.button>
                @if ($network !== 'coolify')
                    <x-modal-confirmation title="Confirm Destination Deletion?" buttonTitle="Delete Destination" isErrorButton
                        submitAction="delete" :actions="['This will delete the selected destination/network.']" confirmationText="{{ $destination->name }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the Destination Name below"
                        shortConfirmationLabel="Destination Name" :confirmWithPassword="false" step2ButtonText="Permanently Delete"
                        canGate="delete" :canResource="$destination" />
                @endif
            </div>
        </div>

        @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">A simple Docker network.</p>
        @else
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">A swarm Docker network. WIP</p>
        @endif
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$destination" id="name" label="Name" />
            <x-forms.input id="serverIp" label="Server IP" readonly />
            @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
                <x-forms.input id="network" label="Docker Network" readonly />
            @endif
        </div>
    </form>
</div>
