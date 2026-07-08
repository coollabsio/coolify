<div>
    <x-slot:title>
        Sentinel Logs | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-2 md:gap-8 md:flex-row">
        <x-server.sidebar-sentinel :server="$server" :parameters="$parameters" />
        <div class="w-full">
            <h2 class="pb-4">Logs</h2>
            <livewire:project.shared.get-logs :server="$server" container="coolify-sentinel" displayName="Sentinel" :collapsible="false" />
        </div>
    </div>
</div>
