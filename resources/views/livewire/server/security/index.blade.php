<div @if ($isWaitingForConnectionCheck) wire:poll.3s="pollConnectionCheck" @endif>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Security | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="security" activeSubMenu="overview" />

        {{-- Links sent before the patching sections got their own page (notifications) end with their section ID. --}}
        <div class="application-settings-form flex w-full min-w-0 flex-col gap-6"
            x-data
            x-init="if (['#server-patching-overview-section', '#server-package-updates-section', '#server-held-updates-section'].includes(location.hash)) { window.location.replace(@js(route('server.security.patches', ['server_uuid' => $server->uuid])) + location.hash) }">
            <livewire:server.security.host-key :server_uuid="$server->uuid" />
            <livewire:server.security.terminal-access :server_uuid="$server->uuid" />
        </div>
    </div>
</div>
