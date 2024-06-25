<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Server Configurations | Coolify
    </x-slot>
    <x-server.navbar :server="$server" :parameters="$parameters" />
    <livewire:server.form :server="$server" />
    @if ($server->isFunctional() && $server->isMetricsEnabled())
        <div class="pt-10">
            <livewire:server.charts :server="$server" />
        </div>
    @endif
    <livewire:server.delete :server="$server" />
</div>
