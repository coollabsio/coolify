<div x-data="{ stopProxy: false }">
    <x-naked-modal show="stopProxy" action="stopProxy"
        message='Are you sure you would like to stop the proxy? All resources will be unavailable.' />
    @if ($server->settings->is_reachable)
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
                                <x-forms.button type="submit">Save</x-forms.button>
                                @if ($server->extra_attributes->proxy_status === 'exited')
                                    <x-forms.button wire:click.prevent="switchProxy">Switch Proxy</x-forms.button>
                                @endif
                                <livewire:server.proxy.status :server="$server" />
                            </div>
                            <div class="pb-4 text-sm">Traefik v2</div>
                            @if (
                                $server->extra_attributes->proxy_last_applied_settings &&
                                    $server->extra_attributes->proxy_last_saved_settings !== $server->extra_attributes->proxy_last_applied_settings)
                                <div class="text-sm text-red-500">Configuration out of sync. Restart to get the new configs.
                                </div>
                            @endif
                            <div class="container w-full py-4 mx-auto">
                                <livewire:activity-monitor :header="true" />
                            </div>
                            <div class="flex flex-col gap-2">
                                <x-forms.textarea label="Configuration file: traefik.conf" class="text-xs" noDirty
                                    name="proxy_settings" wire:model.defer="proxy_settings" rows="30" />
                                <x-forms.button wire:click.prevent="resetProxy">
                                    Reset configuration to default
                                </x-forms.button>
                            </div>
                        </form>
                    @endif
                @endisset
            </div>
        @else
            <div>
                <h2>Proxy</h2>
                <div class="pt-2 pb-10 text-sm">Select a proxy you would like to use on this server.</div>
                <div class="flex gap-2">
                    <x-forms.button class="w-32 box" wire:click="setProxy('{{ \App\Enums\ProxyTypes::TRAEFIK_V2 }}')">
                        Traefik
                        v2
                    </x-forms.button>
                    <x-forms.button disabled class="w-32 box">
                        Nginx
                    </x-forms.button>
                    <x-forms.button disabled class="w-32 box">
                        Caddy
                    </x-forms.button>
                </div>
            </div>
        @endif
    @else
        <div class="text-sm">Server is not validated. Validate first.</div>
    @endif
</div>
