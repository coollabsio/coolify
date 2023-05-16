<div x-data="{ stopProxy: false }">
    <x-naked-modal show="stopProxy" action="stopProxy"
        message='Are you sure you would like to stop the proxy? All resources will be unavailable.' />
    @if ($server->settings->is_validated)
        <div class="flex items-center gap-2">
            <h3>Proxy</h3>
            <div>{{ $server->extra_attributes->proxy_status }}</div>
        </div>

        @if ($server->extra_attributes->proxy_type)
            <div wire:poll="proxyStatus">
                @if (
                    $server->extra_attributes->last_applied_proxy_settings &&
                        $server->extra_attributes->last_saved_proxy_settings !== $server->extra_attributes->last_applied_proxy_settings)
                    <div class="text-red-500">Configuration out of sync.</div>
                @endif
                @if ($server->extra_attributes->proxy_status !== 'running')
                    <x-inputs.button isBold wire:click="installProxy">
                        Start
                    </x-inputs.button>
                @else
                    <x-inputs.button isWarning x-on:click.prevent="stopProxy = true">Stop
                    </x-inputs.button>
                @endif
                <span x-data="{ showConfiguration: false }">
                    <x-inputs.button isBold x-on:click.prevent="showConfiguration = !showConfiguration">Show
                        Configuration
                    </x-inputs.button>
                    <div class="pt-4">
                        <livewire:activity-monitor />
                    </div>
                    <template x-if="showConfiguration">
                        <div x-init="$wire.checkProxySettingsInSync">
                            <h3>Configuration</h3>
                            <div wire:loading wire:target="checkProxySettingsInSync">
                                <x-proxy.loading />
                            </div>
                            @isset($proxy_settings)
                                <form wire:submit.prevent='saveConfiguration'>
                                    <div class="pb-2">
                                        <x-inputs.button isBold>Save</x-inputs.button>
                                        <x-inputs.button wire:click.prevent="resetProxy">
                                            Reset Configuration
                                        </x-inputs.button>
                                    </div>
                                    <textarea wire:model.defer="proxy_settings" class="w-full" rows="30"></textarea>
                                </form>
                            @endisset
                        </div>
                    </template>
                </span>
            </div>
        @else
            <select wire:model="selectedProxy">
                <option value="{{ \App\Enums\ProxyTypes::TRAEFIK_V2 }}">
                    {{ \App\Enums\ProxyTypes::TRAEFIK_V2 }}
                </option>
            </select>
            <x-inputs.button isBold wire:click="setProxy">Set Proxy</x-inputs.button>
        @endif
    @else
        <p>Server is not validated. Validate first.</p>
    @endif

</div>
