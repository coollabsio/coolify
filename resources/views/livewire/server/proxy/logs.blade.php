<div>
    <x-slot:title>
        Proxy Logs | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-4 md:gap-8 md:flex-row">
        <x-server.sidebar-proxy :server="$server" :parameters="$parameters" />
        <div class="w-full">
            <h2 class="pb-4">Logs</h2>
            <livewire:project.shared.get-logs :server="$server" container="coolify-proxy" displayName="Coolify Proxy" :collapsible="false" />
        </div>
    </div>
</div>
