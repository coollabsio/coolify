<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Server Configurations | Coolify
    </x-slot>
    <x-server.navbar :server="$server" :parameters="$parameters" />
    <livewire:server.form :server="$server" />
    <livewire:server.delete :server="$server" />
    @if ($server->isFunctional() && $server->isMetricsEnabled())
        <div class="pt-10">
            <script>
                let theme = localStorage.theme
                let baseColor = '#FCD452'
                let textColor = '#ffffff'

                function checkTheme() {
                    theme = localStorage.theme
                    if (theme == 'dark') {
                        baseColor = '#FCD452'
                        textColor = '#ffffff'
                    } else {
                        baseColor = 'black'
                        textColor = '#000000'
                    }
                }
            </script>
            <livewire:charts.server-cpu :server="$server" />
            <livewire:charts.server-memory :server="$server" />
        </div>
    @endif
</div>
