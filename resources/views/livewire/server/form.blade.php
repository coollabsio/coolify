<div x-data="{ deleteServer: false }">
    <x-naked-modal show="deleteServer" message='Are you sure you would like to delete this server?' />
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-96">
                <x-inputs.input id="server.name" label="Name" required />
                <x-inputs.input id="server.description" label="Description" />
                <x-inputs.input disabled type="checkbox" id="server.settings.is_part_of_swarm"
                    label="Is it part of a Swarm cluster?" />
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

        <div class="flex items-center gap-2">
            <x-inputs.button isBold type="submit">Save</x-inputs.button>

            <x-inputs.button isBold wire:click.prevent='validateServer'>
                @if ($server->settings->is_validated)
                    Check Connection
                @else
                    Validate Server
                @endif
            </x-inputs.button>

            {{-- <x-inputs.button isBold wire:click.prevent='installDocker'>Install Docker</x-inputs.button> --}}
            <x-inputs.button isWarning x-on:click.prevent="deleteServer = true">
                Delete
            </x-inputs.button>
        </div>
        <div class="pt-3">
            @isset($uptime)
                <p>Connection: OK</p>
                <p>Uptime: {{ $uptime }}</p>
            @endisset
            @isset($dockerVersion)
                <p>Docker Engine {{ $dockerVersion }}</p>
            @endisset
            @isset($dockerComposeVersion)
                <p>{{ $dockerComposeVersion }}</p>
            @endisset
        </div>
    </form>
</div>
