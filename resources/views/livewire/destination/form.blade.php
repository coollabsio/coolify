<div>
    <form class="flex flex-col gap-4" wire:submit.prevent='submit'>
        <x-inputs.input id="destination.name" label="Name" />
        <x-inputs.input id="destination.server.ip" label="Server IP" readonly />
        @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
            <x-inputs.input id="destination.network" label="Docker Network" readonly />
        @endif
        <div>
            <x-inputs.button>
                Submit
            </x-inputs.button>
            <x-inputs.button confirm='Are you sure you would like to delete this private key?'
                confirmAction="delete('{{ $destination->id }}')">
                Delete
            </x-inputs.button>
        </div>
    </form>
</div>
