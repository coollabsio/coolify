<div>
    @if ($server->isFunctional())
        @if (data_get($server,'proxy.type'))
        <x-modal submitWireAction="proxyStatusUpdated" modalId="startProxy">
            <x-slot:modalBody>
                <livewire:activity-monitor header="Proxy Startup Logs" />
            </x-slot:modalBody>
            <x-slot:modalSubmit>
                <x-forms.button onclick="startProxy.close()" type="submit">
                    Close
                </x-forms.button>
            </x-slot:modalSubmit>
        </x-modal>
            <div x-init="$wire.loadProxyConfiguration">
                @if ($selectedProxy === 'TRAEFIK_V2')
                    <form wire:submit.prevent='submit'>
                        <div class="flex items-center gap-2">
                            <h2>Proxy</h2>
                            <x-forms.button type="submit">Save</x-forms.button>
                            @if ($server->proxy->status === 'exited')
                                <x-forms.button wire:click.prevent="change_proxy">Switch Proxy</x-forms.button>
                            @endif
                        </div>
                        <div class="pt-3 pb-4 ">Traefik v2</div>
                        @if (
                            $server->proxy->last_applied_settings &&
                                $server->proxy->last_saved_settings !== $server->proxy->last_applied_settings)
                            <div class="text-red-500 ">Configuration out of sync. Restart the proxy to apply the new
                                configurations.
                            </div>
                        @endif
                        <x-forms.input placeholder="https://coolify.io" id="redirect_url" label="Default Redirect 404"
                            helper="All urls that has no service available will be redirected to this domain.<span class='text-helper'>You can set to your main marketing page or your social media link.</span>" />
                        <div wire:loading wire:target="loadProxyConfiguration" class="pt-4">
                            <x-loading text="Loading proxy configuration..." />
                        </div>
                        <div wire:loading.remove wire:target="loadProxyConfiguration">
                            @if ($proxy_settings)
                                <div class="flex flex-col gap-2 pt-2">
                                    <x-forms.textarea label="Configuration file: traefik.conf" name="proxy_settings"
                                        wire:model.defer="proxy_settings" rows="30" />
                                    <x-forms.button wire:click.prevent="reset_proxy_configuration">
                                        Reset configuration to default
                                    </x-forms.button>
                                </div>
                            @endif
                        </div>
                    </form>
                @elseif($selectedProxy === 'NONE')
                    <div class="flex items-center gap-2">
                        <h2>Proxy</h2>
                        @if ($server->proxy->status === 'exited')
                            <x-forms.button wire:click.prevent="change_proxy">Switch Proxy</x-forms.button>
                        @endif
                    </div>
                    <div class="pt-3 pb-4">None</div>
                @else
                <div class="flex items-center gap-2">
                    <h2>Proxy</h2>
                    @if ($server->proxy->status === 'exited')
                        <x-forms.button wire:click.prevent="change_proxy">Switch Proxy</x-forms.button>
                    @endif
                </div>
                @endif
            @else
                <div>
                    <h2>Proxy</h2>
                    <div class="subtitle ">Select a proxy you would like to use on this server.</div>
                    <div class="flex gap-2">
                        <x-forms.button class="w-32 box" wire:click="select_proxy('NONE')">
                            Custom (None)
                        </x-forms.button>
                        <x-forms.button class="w-32 box" wire:click="select_proxy('TRAEFIK_V2')">
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
