<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-96">
                <x-form-input id="server.name" label="Name" required />
                <x-form-input id="server.description" label="Description" />
            </div>
            <div class="flex flex-col w-96">
                @if ($server->id === 0)
                    <x-form-input id="server.ip" label="IP Address" readonly />
                    <x-form-input id="server.user" label="User" readonly />
                    <x-form-input type="number" id="server.port" label="Port" readonly />
                @else
                    <x-form-input id="server.ip" label="IP Address" required />
                    <x-form-input id="server.user" label="User" required />
                    <x-form-input type="number" id="server.port" label="Port" required />
                @endif
            </div>
        </div>
        <div>
            <button class="w-16 mt-4" type="submit">
                Submit
            </button>
            <button wire:click.prevent='checkServer'>Check Server</button>
            <button wire:click.prevent='installDocker'>Install Docker</button>
        </div>
    </form>
    @isset($uptime)
        <p>Connection OK</p>
        <p>Uptime: {{ $uptime }}</p>
        <p>{{ $dockerVersion }}</p>
        <p>{{ $dockerComposeVersion }}</p>
    @endisset

</div>
