<div x-data="{ stopProxy: false }">
    <x-naked-modal show="stopProxy" action="stopProxy"
        message='Are you sure you would like to stop the proxy? All resources will be unavailable.' />
    @if ($server->settings->is_validated)
        <div class="flex items-center gap-2 mb-4">
            <h2>Proxy</h2>
            <div>{{ $server->extra_attributes->proxy_status }}</div>
        </div>

        @if ($server->extra_attributes->proxy_type)
            <div wire:poll="proxyStatus">
                @if (
                    $server->extra_attributes->last_applied_proxy_settings &&
                        $server->extra_attributes->last_saved_proxy_settings !== $server->extra_attributes->last_applied_proxy_settings)
                    <div class="text-red-500">Configuration out of sync.</div>
                    <x-inputs.button wire:click="installProxy">
                        Reconfigure
                    </x-inputs.button>
                @endif
                @if ($server->extra_attributes->proxy_status !== 'running')
                    <x-inputs.button wire:click="installProxy">
                        Start
                    </x-inputs.button>
                @else
                    <x-inputs.button x-on:click.prevent="stopProxy = true">Stop
                    </x-inputs.button>
                @endif
                <div class="py-4">
                    <livewire:activity-monitor />
                </div>
                <div x-init="$wire.checkProxySettingsInSync">
                    <div wire:loading wire:target="checkProxySettingsInSync">
                        <x-loading />
                    </div>
                    @isset($proxy_settings)
                        <h3>Configuration</h3>
                        @if ($selectedProxy->value === 'TRAEFIK_V2')
                            <form wire:submit.prevent='saveConfiguration'>
                                <div class="py-2 pb-8">
                                    <x-inputs.button type="submit">Save</x-inputs.button>
                                    <x-inputs.button wire:click.prevent="resetProxy">
                                        Reset Configuration
                                    </x-inputs.button>
                                </div>
                                <h4>traefik.conf</h4>
                                <x-inputs.textarea noDirty name="proxy_settings" wire:model.defer="proxy_settings"
                                    rows="30" />
                            </form>
                        @endif
                    @endisset
                </div>
            </div>
        @else
            <select wire:model="selectedProxy">
                <option value="{{ \App\Enums\ProxyTypes::TRAEFIK_V2 }}">
                    {{ \App\Enums\ProxyTypes::TRAEFIK_V2 }}
                </option>
            </select>
            <x-inputs.button wire:click="setProxy">Set Proxy</x-inputs.button>
        @endif
    @else
        <p>Server is not validated. Validate first.</p>
    @endif

</div>
