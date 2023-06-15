<div x-data="{ deleteServer: false }">
    <x-naked-modal show="deleteServer" message='Are you sure you would like to delete this server?' />
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex gap-2">
            <h2>General</h2>
            <x-forms.button type="submit">Save</x-forms.button>
            @if ($server->id !== 0)
                <x-forms.button x-on:click.prevent="deleteServer = true">
                    Delete
                </x-forms.button>
            @endif
        </div>
        <div class="flex flex-col gap-2 ">
            <div class="flex flex-col w-full gap-2 lg:flex-row">
                @if ($server->id === 0)
                    <x-forms.input id="server.name" label="Name" readonly required />
                    <x-forms.input id="server.description" label="Description" readonly />
                @else
                    <x-forms.input id="server.name" label="Name" required />
                    <x-forms.input id="server.description" label="Description" />
                @endif

                {{-- <x-forms.checkbox disabled type="checkbox" id="server.settings.is_part_of_swarm"
                    label="Is it part of a Swarm cluster?" /> --}}
            </div>
            <div class="flex flex-col w-full gap-2 lg:flex-row">
                @if ($server->id === 0)
                    <x-forms.input id="server.ip" label="IP Address" readonly required />
                    <x-forms.input id="server.user" label="User" readonly required />
                    <x-forms.input type="number" id="server.port" label="Port" readonly required />
                @else
                    <x-forms.input id="server.ip" label="IP Address" readonly required />
                    <div class="flex gap-2">
                        <x-forms.input id="server.user" label="User" required />
                        <x-forms.input type="number" id="server.port" label="Port" required />
                    </div>
                @endif
            </div>
        </div>
        <h3 class="py-4">Actions</h3>
        @if ($server->settings->is_reachable)
            <div class="flex items-center gap-2">
                <x-forms.button wire:click.prevent='validateServer'>
                    Check Server Details
                </x-forms.button>
                <x-forms.button wire:click.prevent='installDocker' isHighlighted>
                    @if ($server->settings->is_usable)
                        Reconfigure Docker Engine
                    @else
                        Install Docker Engine
                    @endif
                </x-forms.button>
            </div>
        @else
            <div class="w-full">
                <x-forms.button isHighlighted wire:click.prevent='validateServer'>
                    Validate Server
                </x-forms.button>
            </div>
        @endif
        <div class="container w-full py-4 mx-auto">
            <livewire:activity-monitor :header="true" />
        </div>
        @isset($uptime)
            <h4 class="pb-3">Server Info</h4>
            <div class="text-sm">
                <p>Uptime: {{ $uptime }}</p>
                @isset($dockerVersion)
                    <p>Docker Engine {{ $dockerVersion }}</p>
                @endisset
            </div>
        @endisset
    </form>
</div>
