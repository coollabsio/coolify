<div>
    <x-slot:title>
        Sentinel Configuration | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    @if ($server->isFunctional())
        <div class="flex flex-col h-full gap-8 sm:flex-row">
            <x-server.sidebar-sentinel :server="$server" :parameters="$parameters" />
            <div class="w-full">
                <livewire:server.sentinel :server="$server" />
            </div>
        </div>
    @else
        <div>Server is not validated. Validate first.</div>
    @endif
</div>
