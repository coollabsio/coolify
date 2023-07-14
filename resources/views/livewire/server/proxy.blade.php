<div x-data="{ stopProxy: false }">
    <x-naked-modal show="stopProxy" action="stopProxy" title="Stop Proxy"
        message='This proxy will be stopped. It is not reversible. <br>All resources will be unavailable. <br>Please think again.' />
    @if ($server->settings->is_usable)
        @if ($server->proxy->type)
            <div x-init="$wire.checkProxySettingsInSync">
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
                        <div class="container w-full pb-4 mx-auto">
                            <livewire:activity-monitor :header="true" />
                        </div>
                        <x-forms.input placeholder="https://coolify.io" id="redirect_url" label="Default Redirect 404"
                            helper="All urls that has no service available will be redirected to this domain.<span class='text-helper'>You can set to your main marketing page or your social media link.</span>" />
                        <div wire:loading wire:target="checkProxySettingsInSync" class="pt-4">
                            <x-loading text="Loading proxy configuration..." />
                        </div>
                        <div wire:loading.remove wire:target="checkProxySettingsInSync">
                            @if ($proxy_settings)
                                <div class="flex flex-col gap-2 pt-2">
                                    <x-forms.textarea label="Configuration file: traefik.conf" name="proxy_settings"
                                        wire:model.defer="proxy_settings" rows="30" />
                                    <x-forms.button wire:click.prevent="resetProxy">
                                        Reset configuration to default
                                    </x-forms.button>
                                </div>
                            @endif
                        </div>
                    </form>
                @endif
            @else
                <div>
                    <h2>Proxy</h2>
                    <div class="pt-2 pb-10 ">Select a proxy you would like to use on this server.</div>
                    <div class="flex gap-2">
                        <x-forms.button class="w-32 box"
                            wire:click="setProxy('{{ \App\Enums\ProxyTypes::TRAEFIK_V2 }}')">
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
        <div>Server is not validated. Validate first.</div>
    @endif
</div>
