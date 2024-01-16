<div>
    <x-server.navbar :server="$server" :parameters="$parameters" />
    <div class="flex gap-2">
        <x-server.sidebar :server="$server" :parameters="$parameters" />
        <div class="w-full">
            @if ($server->isFunctional())
                <livewire:server.proxy :server="$server" />
            @endif
        </div>
    </div>
</div>
