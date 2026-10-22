<div>
    <x-slot:title>
        TLS Certificates | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="proxy" activeSubMenu="certificates" />
        @if ($server->isFunctional() && $server->proxyType() === \App\Enums\ProxyTypes::TRAEFIK->value)
            <div class="w-full">
                <livewire:server.proxy.certificates :server="$server" />
            </div>
        @else
            <div class="application-settings-form w-full">
                <x-application.settings-section title="TLS certificates"
                    helper="Review certificates stored by Traefik for this server.">
                    <x-empty size="sm" :title="$server->isFunctional() ? 'Traefik required' : 'Server validation required'"
                        :description="$server->isFunctional() ? 'TLS certificate management is available for the Traefik proxy.' : 'Validate this server before viewing its TLS certificates.'"
                        icon-name="servers" />
                </x-application.settings-section>
            </div>
        @endif
    </div>
</div>
