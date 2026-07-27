<div>
    <x-slot:title>
        Sentinel Configuration | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div
        class="server-settings-workspace application-settings-workspace mt-8 grid w-full max-w-[1180px] min-w-0 gap-8 xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar-sentinel :server="$server" :parameters="$parameters" />
        @if ($server->isFunctional())
            <div class="w-full">
                <livewire:server.sentinel :server="$server" />
            </div>
        @else
            <div class="application-settings-form w-full">
                <x-application.settings-section title="Sentinel"
                    helper="Monitor server and container health while collecting metrics.">
                    <x-empty size="sm" title="Server validation required"
                        description="Validate this server before enabling Sentinel.">
                        <x-slot:icon>
                            <x-reicon name="dashboard" class="size-8" />
                        </x-slot:icon>
                    </x-empty>
                </x-application.settings-section>
            </div>
        @endif
    </div>
</div>
