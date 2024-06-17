<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Server Configurations | Coolify
    </x-slot>
    <x-server.navbar :server="$server" :parameters="$parameters" />
    <livewire:server.form :server="$server" />
    <livewire:server.delete :server="$server" />
    @if (isDev())
        <div class="pt-10">
            <livewire:charts.server :server="$server" />
        </div>
    @endif
</div>
