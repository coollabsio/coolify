<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Analytics | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="analytics" />

        <div class="flex w-full min-w-0 flex-col gap-6">
            @can('update', $server)
                <livewire:server.traffic-analytics-settings :server="$server"
                    :key="'server-traffic-analytics-settings-'.$server->uuid" />
            @endcan

            <livewire:analytics :scoped-server-uuid="$server->uuid" :key="'server-analytics-'.$server->uuid"
                :lazy="$server->isTrafficAnalyticsEnabled()" />
        </div>
    </div>
</div>
