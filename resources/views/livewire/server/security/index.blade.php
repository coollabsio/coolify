<div @if ($isWaitingForConnectionCheck) wire:poll.3s="pollConnectionCheck" @endif>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Security | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="security" activeSubMenu="overview" />

        <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <livewire:server.security.host-key :server_uuid="$server->uuid" />
            <livewire:server.security.terminal-access :server_uuid="$server->uuid" />
            {{-- Patching runs commands on the server, so it needs a validated server. The host key must stay visible when a changed key stops the connection. --}}
            @if ($server->isFunctional())
                <livewire:server.security.patches :server_uuid="$server->uuid" />
            @endif
        </div>
    </div>
</div>
