<div x-data="{ stopProxy: false }">
    <x-naked-modal show="stopProxy" action="stopProxy" title="Stop Proxy"
        message='This proxy will be stopped. It is not reversible. <br>All resources will be unavailable. <br>Please think again.' />
    @if ($server->settings->is_reachable)
        @if ($server->proxy->type)
            <div x-init="$wire.checkProxySettingsInSync">
                <div wire:loading wire:target="checkProxySettingsInSync">
                    <x-loading />
                </div>
                <div wire:loading.remove>
                    @if ($proxy_settings)
                        @if ($selectedProxy->value === 'TRAEFIK_V2')
                            <form wire:submit.prevent='saveConfiguration'>
                                <div class="flex items-center gap-2">
                                    <h2>Proxy</h2>
                                    <x-forms.button type="submit">Save</x-forms.button>
                                    @if ($server->proxy->status === 'exited')
                                        <x-forms.button wire:click.prevent="switchProxy">Switch Proxy</x-forms.button>
                                    @endif
                                    <livewire:server.proxy.status :server="$server" />
                                </div>
                                <div class="pt-3 pb-4 ">Traefik v2</div>
                                @if (
                                    $server->proxy->last_applied_settings &&
                                        $server->proxy->last_saved_settings !== $server->proxy->last_applied_settings)
                                    <div class="text-red-500 ">Configuration out of sync. Restart to get the new
                                        configs.
                                    </div>
                                @endif
                                @if ($server->id !== 0)
                                    <x-forms.input id="redirect_url" label="Default redirect"
                                        placeholder="https://coolify.io" />
                                @endif
                                <div class="container w-full mx-auto">
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
                    @else
                        <div class="">Server is not validated. Validate first.</div>
                    @endif
                </div>
            </div>
        @else
            <div>
                <h2>Proxy</h2>
                <div class="pt-2 pb-10 ">Select a proxy you would like to use on this server.</div>
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
        <div class="">Server is not validated. Validate first.</div>
    @endif
</div>
