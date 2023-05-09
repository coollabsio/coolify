<div x-data="{ deleteServer: false }">
    <x-naked-modal show="deleteServer" message='Are you sure you would like to delete this server?' />
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-96">
                <x-inputs.input id="server.name" label="Name" required />
                <x-inputs.input id="server.description" label="Description" />
            </div>
            <div class="flex flex-col w-96">
                @if ($server->id === 0)
                    <x-inputs.input id="server.ip" label="IP Address" readonly />
                    <x-inputs.input id="server.user" label="User" readonly />
                    <x-inputs.input type="number" id="server.port" label="Port" readonly />
                @else
                    <x-inputs.input id="server.ip" label="IP Address" required readonly />
                    <x-inputs.input id="server.user" label="User" required />
                    <x-inputs.input type="number" id="server.port" label="Port" required />
                @endif
            </div>
        </div>
        <div class="flex">
            <x-inputs.button type="submit">Submit</x-inputs.button>
            <x-inputs.button wire:click.prevent='checkServer'>Check Server</x-inputs.button>
            <x-inputs.button wire:click.prevent='installDocker'>Install Docker</x-inputs.button>
            <x-inputs.button isWarning x-on:click.prevent="deleteServer = true">
                Delete
            </x-inputs.button>
        </div>
        <x-inputs.input class="" disabled type="checkbox" id="server.settings.is_validated" label="Validated" />
    </form>

    @isset($uptime)
        <p>Uptime: {{ $uptime }}</p>
    @endisset
    @isset($dockerVersion)
        <p>Docker Engine: {{ $dockerVersion }}</p>
    @endisset
    @isset($dockerComposeVersion)
        <p>Docker Compose: {{ $dockerComposeVersion }}</p>
    @endisset
</div>
