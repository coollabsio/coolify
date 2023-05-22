<div x-data="{ stopProxy: false }">
    <x-naked-modal show="stopProxy" action="stopProxy"
        message='Are you sure you would like to stop the proxy? All resources will be unavailable.' />
    @if ($server->settings->is_validated)
        <div class="flex items-center gap-2 mb-2">
            <h2 class="pb-0">Proxy</h2>
            @if ($server->extra_attributes->proxy_type)
                <x-inputs.button isHighlighted wire:click.prevent="installProxy">
                    Start/Reconfigure Proxy
                </x-inputs.button>
                <x-inputs.button x-on:click.prevent="stopProxy = true">Stop
                </x-inputs.button>
                <div wire:poll="proxyStatus">
                    @if (
                        $server->extra_attributes->last_applied_proxy_settings &&
                            $server->extra_attributes->last_saved_proxy_settings !== $server->extra_attributes->last_applied_proxy_settings)
                        <div class="text-red-500">Configuration out of sync.</div>
                    @endif


                </div>
            @endif
            @if ($server->extra_attributes->proxy_status === 'running')
                <span class="text-xs text-pink-600" wire:loading.delay.longer>Loading current status...</span>
                <div class="flex items-center gap-2 text-sm" wire:loading.remove.delay.longer>
                    <span class="flex w-3 h-3 rounded-full bg-success"></span>
                    <span class="text-green-500">Running</span>
                </div>
            @else
                <span class="text-xs text-pink-600" wire:loading.delay.longer>Loading current status...</span>
                <div class="flex items-center gap-2 text-sm" wire:loading.remove.delay.longer>
                    <span class="flex w-3 h-3 rounded-full bg-error"></span>
                    <span class="text-error">Stopped</span>
                </div>
            @endif
        </div>

        <livewire:activity-monitor />
        @if ($server->extra_attributes->proxy_type)
            <div x-init="$wire.checkProxySettingsInSync">
                <div wire:loading wire:target="checkProxySettingsInSync">
                    <x-loading />
                </div>
                @isset($proxy_settings)
                    @if ($selectedProxy->value === 'TRAEFIK_V2')
                        <form wire:submit.prevent='saveConfiguration'>
                            <div class="flex items-center gap-2">
                                <h3>Configuration</h3>
                                <x-inputs.button type="submit">Save</x-inputs.button>
                                <x-inputs.button wire:click.prevent="resetProxy">
                                    Reset Configuration
                                </x-inputs.button>
                            </div>
                            <h4>traefik.conf</h4>
                            <x-inputs.textarea class="text-xs" noDirty name="proxy_settings"
                                wire:model.defer="proxy_settings" rows="30" />
                        </form>
                    @endif
                @endisset
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
