<div>
    <x-slot:title>
        Sentinel Configuration | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="sentinel" />
        @if ($server->isFunctional())
            <div class="w-full">
                <livewire:server.sentinel :server="$server" />
            </div>
        @else
            <div class="application-settings-form w-full">
                <x-application.settings-section title="Sentinel"
                    helper="Monitor server and container health while collecting metrics.">
                    <x-empty size="sm" title="Server validation required"
                        description="Validate this server before enabling Sentinel."
                        icon-name="dashboard" />
                </x-application.settings-section>
            </div>
        @endif
    </div>
</div>
