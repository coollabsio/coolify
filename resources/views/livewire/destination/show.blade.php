<div>
    <x-slot:title>
        {{ $destination->name }} | Destination | Coolify
    </x-slot>

    @include('livewire.destination.navbar', ['destination' => $destination])

    <form wire:submit="submit" class="application-settings-form">
        <x-unsaved-bar action="submit" />

        <x-application.settings-section title="{{ $destination->name }}"
            :description="$destination->getMorphClass() === 'App\Models\StandaloneDocker'
                ? 'Docker network used to connect deployed resources.'
                : 'Deprecated Docker Swarm network.'">
            <x-slot:actions>
                @if ($destination->getMorphClass() !== 'App\Models\StandaloneDocker')
                    <x-status-badge label="Deprecated" type="warning" />
                @endif
                @if ($network !== 'coolify')
                    <x-modal-confirmation title="Confirm Destination Deletion?"
                        buttonTitle="Delete destination" isErrorButton submitAction="delete"
                        :actions="['This will delete the selected destination/network.']"
                        confirmationText="{{ $destination->name }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the Destination Name below"
                        shortConfirmationLabel="Destination Name" :confirmWithPassword="false"
                        step2ButtonText="Permanently Delete" canGate="delete"
                        :canResource="$destination" />
                @endif
            </x-slot:actions>

            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input canGate="update" :canResource="$destination" id="name" label="Name" />
                <x-forms.input id="serverIp" label="Server IP" readonly />
                @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
                    <div class="lg:col-span-2">
                        <x-forms.input id="network" label="Docker network" readonly />
                    </div>
                @endif
            </div>
        </x-application.settings-section>
    </form>
</div>
