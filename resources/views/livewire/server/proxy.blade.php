<div>
    <h2> Proxy </h2>

    @if($this->server->extra_attributes->proxy)
        <div class="mt-6">
            <div>
                Proxy type: {{ $this->server->extra_attributes->proxy  }}
            </div>

            <div id="proxy_options" x-init="$wire.checkProxySettingsInSync()" class="relative w-fit">

                {{-- Proxy is being checked against DB information --}}
                @if(! $this->is_check_proxy_complete)
                    @include('livewire.server._proxy.loading')
                @endif

                @if($this->is_check_proxy_complete && (! $this->is_proxy_settings_in_sync) )
                    @include('livewire.server._proxy.problems')
                @endif

                @include('livewire.server._proxy.options')
            </div>

        </div>
    @else
        {{-- There is no Proxy installed --}}

        No proxy installed.
        <select wire:model="selectedProxy">
            <option value="{{ \App\Enums\ProxyTypes::TRAEFIK_V2 }}">
                {{ \App\Enums\ProxyTypes::TRAEFIK_V2 }}
            </option>
        </select>
        <button wire:click="runInstallProxy">Install Proxy</button>
    @endif

    <livewire:activity-monitor />

</div>
