<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Server Configurations | Coolify
    </x-slot>
    <x-server.navbar :server="$server" :parameters="$parameters" />
    <livewire:server.form :server="$server" />
    <livewire:server.delete :server="$server" />
    @if ($server->isFunctional() && $server->isMetricsEnabled())
        <div class="pt-10">
            <livewire:charts.server-cpu :server="$server" />
            <livewire:charts.server-memory :server="$server" />
        </div>
    @endif
</div>
