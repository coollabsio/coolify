<div x-data="{ stopProxy: false }">
    <x-naked-modal show="stopProxy" action="stopProxy"
        message='Are you sure you would like to stop the proxy? All resources will be unavailable.' />
    @if ($server->settings->is_validated)
        @if ($server->extra_attributes->proxy_type)
            <div x-init="$wire.checkProxySettingsInSync">
                <div wire:loading wire:target="checkProxySettingsInSync">
                    <x-loading />
                </div>
                @isset($proxy_settings)
                    @if ($selectedProxy->value === 'TRAEFIK_V2')
                        <form wire:submit.prevent='saveConfiguration({{ $server }})'>
                            <div class="flex items-center gap-2">
                                <h2>Proxy</h2>
                                <livewire:server.proxy.status :server="$server" />
                            </div>
                            <div class="pb-4 text-sm">Traefik v2</div>
                            @if (
                                $server->extra_attributes->proxy_last_applied_settings &&
                                    $server->extra_attributes->proxy_last_saved_settings !== $server->extra_attributes->proxy_last_applied_settings)
                                <div class="text-sm text-red-500">Configuration out of sync. Restart to get the new configs.
                                </div>
                            @endif
                            <x-forms.button type="submit">Save</x-forms.button>
                            <x-forms.button wire:click.prevent="resetProxy">
                                Reset to default
                            </x-forms.button>
                            <div class="pt-4 pb-0 text-xs">traefik.conf</div>
                            <x-forms.textarea class="text-xs" noDirty name="proxy_settings"
                                wire:model.defer="proxy_settings" rows="30" />
                        </form>
                    @endif
                @endisset
            </div>
        @else
            <div>
                <h2>Select a Proxy</h2>
                <x-forms.button wire:click="setProxy('{{ \App\Enums\ProxyTypes::TRAEFIK_V2 }}')">Traefik v2
                </x-forms.button>
            </div>
        @endif
        <div class="container w-full pt-4 mx-auto">
            <livewire:activity-monitor :header="true" />
        </div>
    @else
        <div class="text-sm">Server is not validated. Validate first.</div>
    @endif
</div>
