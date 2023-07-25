<div x-data="{ changeLocalhost: false }">
    @if ($server->settings->is_reachable)
        <x-modal yesOrNo modalId="{{ $modalId }}" modalTitle="Delete Server">
            <x-slot:modalBody>
                <p>This server will be deleted. It is not reversible. <br>Please think again..</p>
            </x-slot:modalBody>
        </x-modal>
        <x-naked-modal show="changeLocalhost" action="submit" title="Change localhost"
            message='You could lost a lot of functionalities if you change the server details of the server where Coolify is running on.<br>Please think again.' />
        <form wire:submit.prevent='submit' class="flex flex-col">
            <div class="flex gap-2">
                <h2>General</h2>
                @if ($server->id === 0)
                    <x-forms.button x-on:click.prevent="changeLocalhost = true">Save</x-forms.button>
                @else
                    <x-forms.button type="submit">Save</x-forms.button>
                @endif
            </div>
            <div class="flex flex-col gap-2 ">
                <div class="flex flex-col w-full gap-2 lg:flex-row">
                    <x-forms.input id="server.name" label="Name" required />
                    <x-forms.input id="server.description" label="Description" />
                    <x-forms.input placeholder="https://example.com" id="wildcard_domain" label="Wildcard Domain"
                        helper="Wildcard domain for your applications. If you set this, you will get a random generated domain for your new applications.<br><span class='font-bold text-white'>Example</span>In case you set:<span class='text-helper'>https://example.com</span>your applications will get: <span class='text-helper'>https://randomId.example.com</span>" />
                    {{-- <x-forms.checkbox disabled type="checkbox" id="server.settings.is_part_of_swarm"
                    label="Is it part of a Swarm cluster?" /> --}}
                </div>
                <div class="flex flex-col w-full gap-2 lg:flex-row">
                    @if ($server->id === 0)
                        <x-forms.input id="server.ip" label="IP Address" required />
                    @else
                        <x-forms.input id="server.ip" label="IP Address" readonly required />
                    @endif
                    <div class="flex gap-2">
                        <x-forms.input id="server.user" label="User" required />
                        <x-forms.input type="number" id="server.port" label="Port" required />
                    </div>
                </div>
            </div>
            <h3 class="py-4">Settings</h3>
            <div class="flex items-center w-64 gap-2">
                <x-forms.input id="cleanup_after_percentage" label="Disk Cleanup threshold (%)" required
                    helper="Disk cleanup job will be executed if disk usage is more than this number." />
            </div>
            <h3 class="py-4">Actions</h3>
            <div class="flex items-center gap-2">
                <x-forms.button wire:click.prevent='validateServer'>
                    Check Server Details
                </x-forms.button>
                @if ($server->id !== 0)
                    <x-forms.button wire:click.prevent='installDocker' isHighlighted>
                        @if ($server->settings->is_usable)
                            Reconfigure Docker Engine
                        @else
                            Install Docker Engine
                        @endif
                    </x-forms.button>
                @endif
            </div>
            <div class="container w-full py-4 mx-auto">
                <livewire:activity-monitor :header="true" />
            </div>
            @isset($uptime)
                <h3 class="pb-3">Server Info</h3>
                <div class="py-2 pb-4">
                    <p>Uptime: {{ $uptime }}</p>
                    @isset($dockerVersion)
                        <p>Docker Engine {{ $dockerVersion }}</p>
                    @endisset
                </div>
            @endisset
        </form>
        <h3>Danger Zone</h3>
        <div class="">Woah. I hope you know what are you doing.</div>
        <h4 class="pt-4">Delete Server</h4>
        <div class="pb-4">This will remove this server from Coolify. Beware! There is no coming
            back!
        </div>
        @if ($server->id !== 0 || isDev())
            <x-forms.button isError isModal modalId="{{ $modalId }}">
                Delete
            </x-forms.button>
        @endif
    @else
        <div class="w-full">
            <div class="cursor-pointer box" wire:click.prevent='validateServer'>
                Validate Server
            </div>
        </div>
    @endif
</div>
