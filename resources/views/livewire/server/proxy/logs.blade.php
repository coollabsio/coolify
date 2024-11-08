<div>
    <x-slot:title>
        Proxy Logs | Coolify
    </x-slot>
    <x-server.navbar :server="$server" :parameters="$parameters" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar-proxy :server="$server" :parameters="$parameters" />
        <div class="w-full">
            <h2 class="pb-4">Logs</h2>
            <livewire:project.shared.get-logs :server="$server" container="coolify-proxy" />
        </div>
    </div>
</div>
