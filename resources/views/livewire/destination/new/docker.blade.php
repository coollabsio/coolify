@can('createAnyResource')
    <div class="w-full ">
        <div class="subtitle">Destinations are used to segregate resources by network.</div>
        <form class="flex flex-col gap-4" wire:submit='submit'>
            <div class="flex gap-2">
                <x-forms.input id="name" label="Name" required />
                <x-forms.input id="network" label="Network" required />
            </div>
            <x-forms.select id="serverId" label="Select a server" required wire:change="generateName">
                <option disabled>Select a server</option>
                @foreach ($servers as $server)
                    <option value="{{ $server->id }}">{{ $server->name }}</option>
                @endforeach
            </x-forms.select>
            <x-forms.input id="bindIp" label="Bind IP (optional)" placeholder="e.g. 192.168.1.10"
                helper="Bind deployments on this destination to a specific host IP via a dedicated Traefik entrypoint. Use for LAN-only or multi-homed setups. Leave empty to use the server's primary IP. LAN IPs cannot use Let's Encrypt — provide your own certificate via dynamic configuration. Note: macOS Docker hosts (Docker Desktop, OrbStack) cannot enforce per-IP port bindings; this feature only isolates traffic correctly on Linux." />
            <x-forms.button type="submit">
                Continue
            </x-forms.button>
        </form>
    </div>
@else
    <x-callout type="warning" title="Permission Required">
        You don't have permission to create new destinations. Please contact your team administrator for access.
    </x-callout>
@endcan
