<div>
    <x-slot:title>
        Proxy Gateway | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar-proxy :server="$server" :parameters="$parameters" />
        @if ($server->isFunctional())
            <div class="w-full">
                <livewire:server.proxy.gateway :server="$server" />
            </div>
        @else
            <div>Server is not validated. Validate first.</div>
        @endif
    </div>
</div>
