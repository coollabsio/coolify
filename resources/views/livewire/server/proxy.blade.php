<div>
    <h2>Proxy</h2>

    @if ($this->server->extra_attributes->proxy)
        <div>
            <div>
                Proxy type: {{ $this->server->extra_attributes->proxy }}
            </div>

            <div id="proxy_options" x-init="$wire.checkProxySettingsInSync()" class="relative w-fit">

                {{-- Proxy is being checked against DB information --}}
                @if (!$this->is_check_proxy_complete)
                    <x-proxy.loading />
                @endif

                @if ($this->is_check_proxy_complete && !$this->is_proxy_settings_in_sync)
                    <x-proxy.problems />
                @else
                    <x-proxy.options />
                @endif

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
