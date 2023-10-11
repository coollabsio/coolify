<div>
    <x-server.navbar :server="$server" :parameters="$parameters" />
    <div class="flex gap-2">
        <x-server.sidebar :server="$server" :parameters="$parameters" />
        <div class="w-full">
            <livewire:project.shared.get-logs :server="$server" container="coolify-proxy" />
        </div>
    </div>
</div>
