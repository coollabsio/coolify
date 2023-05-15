<div x-data="{ deleteServer: false }">
    <x-naked-modal show="deleteServer" message='Are you sure you would like to delete this server?' />
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex flex-col pb-4">
            <div class="flex items-center gap-2">
                <div class="text-3xl font-bold">Server</div>
                <x-inputs.button isBold type="submit">Submit</x-inputs.button>
                <x-inputs.button isWarning x-on:click.prevent="deleteServer = true">
                    Delete
                </x-inputs.button>
            </div>
            <div>
                @if ($server->settings->is_validated)
                    <div class="text-green-400/90">Validated</div>
                @else
                    <div class="text-red-400/90">Not validated</div>
                @endif
            </div>
        </div>

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
        <div class="flex gap-2">
            <x-inputs.button isBold wire:click.prevent='validateServer'>Validate Server</x-inputs.button>
            <x-inputs.button isBold wire:click.prevent='installDocker'>Install Docker</x-inputs.button>

        </div>
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
