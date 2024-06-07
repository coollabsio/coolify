<div>
    <x-slot:title>
        Proxy Configuration | Coolify
    </x-slot>
    <x-server.navbar :server="$server" :parameters="$parameters" />
    @if ($server->isFunctional())
        <div class="flex gap-2">
            <x-server.sidebar :server="$server" :parameters="$parameters" />
            <div class="w-full">
                <livewire:server.proxy :server="$server" />
            </div>
        </div>
        @else
        <div>Server is not validated. Validate first.</div>
    @endif
</div>
