<div>
    <form class="flex flex-col">
        <div class="flex items-center gap-2">
            <h1>Destination</h1>
            <a {{ wireNavigate() }} href="{{ route('destination.index', ['server' => $destination->server->uuid]) }}">
                <x-forms.button>Back</x-forms.button>
            </a>
            <x-forms.button canGate="update" :canResource="$destination" wire:click.prevent='submit'
                type="submit">Save</x-forms.button>
            @if ($network !== 'coolify')
                <x-modal-confirmation title="Remove Destination?" buttonTitle="Remove Destination" isErrorButton
                    submitAction="delete" :actions="['Remove this network from Destinations.']"
                    safeTitle="Remove from Destinations" safeButtonTitle="Remove Destination"
                    safeMessage="This removes the network from Destinations. The Docker network will not be deleted."
                    warningMessage="The real Docker network will be permanently deleted. This operation cannot be undone."
                    :safeActions="[
                        'Remove this network from Destinations.',
                        'Keep the Docker network on the server.',
                        'Keep existing runtime containers and network data.',
                        'Keep the network available in network management.',
                        'Allow this network to be added as a Destination again later.',
                    ]"
                    :permanentActions="[
                        'Permanently remove the real Docker network: '.$network,
                        'Remove the Destination association.',
                        'Remove local network inventory and metadata after Docker confirms deletion.',
                    ]"
                    :checkboxes="[[
                        'id' => 'deleteNetwork',
                        'label' => 'Delete Docker network permanently.',
                        'default_warning' => 'Keep Docker network on the server.',
                    ]]"
                    confirmationText="{{ $network }}"
                    confirmationLabel="Please confirm permanent deletion by entering the Docker network name below"
                    shortConfirmationLabel="Docker network name" confirmWithTextAction="deleteNetwork"
                    :initialActions="[]"
                    :inlineActionSelection="true"
                    :confirmWithPassword="false" step2ButtonText="Confirm"
                    canGate="delete" :canResource="$destination" />
            @endif
        </div>

        @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
            <div class="subtitle ">A simple Docker network.</div>
        @else
            <div class="subtitle flex items-center gap-2">A swarm Docker network.
                <x-deprecated-badge />
            </div>
        @endif
        @include('livewire.destination.navbar', ['destination' => $destination])

        <div class="flex gap-2 pt-4">
            <x-forms.input canGate="update" :canResource="$destination" id="name" label="Name" />
            <x-forms.input id="serverIp" label="Server IP" readonly />
            @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
                <x-forms.input id="network" label="Docker Network" readonly />
            @endif
        </div>
    </form>
</div>
