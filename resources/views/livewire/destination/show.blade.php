<div>
    <form class="flex flex-col">
        <div class="flex items-center gap-2">
            <h1>Destination</h1>
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
        @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker' && ! $destination->server->isSwarm())
            <div class="flex gap-2 pt-2">
                <x-forms.input canGate="update" :canResource="$destination" id="bindIp" label="Bind IP (optional)"
                    placeholder="e.g. 192.168.1.10"
                    helper="Bind deployments on this destination to a specific host IP via a dedicated Traefik entrypoint. Use for LAN-only or multi-homed setups. Leave empty to use the server's primary IP. Changing this restarts the proxy. LAN IPs cannot use Let's Encrypt — provide your own certificate via dynamic configuration. Note: macOS Docker hosts (Docker Desktop, OrbStack) cannot enforce per-IP port bindings; this feature only isolates traffic correctly on Linux." />
            </div>
        @endif
    </form>
</div>
