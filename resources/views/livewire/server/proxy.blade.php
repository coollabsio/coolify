<div>
    @if (data_get($server, 'proxy.type'))
        <div x-init="$wire.loadProxyConfiguration">
            @if ($selectedProxy !== 'NONE')
                <form wire:submit='submit'>
                    <div class="flex items-center gap-2">
                        <h2>Configuration</h2>
                        @if ($server->proxy->status === 'exited')
                            <x-forms.button wire:click.prevent="change_proxy">Switch Proxy</x-forms.button>
                        @else
                            <x-forms.button disabled wire:click.prevent="change_proxy">Switch Proxy</x-forms.button>
                        @endif
                        <x-forms.button type="submit">Save</x-forms.button>

                    </div>
                    <div class="pb-4 ">Before switching proxies, please read <a
                            href="https://coolify.io/docs/server/switching-proxies">this</a>.</div>
                    @if ($server->proxyType() === 'TRAEFIK_V2')
                        <div class="pb-4">Traefik v2</div>
                    @elseif ($server->proxyType() === 'CADDY')
                        <div class="pb-4 ">Caddy</div>
                    @endif
                    @if (
                        $server->proxy->last_applied_settings &&
                            $server->proxy->last_saved_settings !== $server->proxy->last_applied_settings)
                        <div class="text-red-500 ">Configuration out of sync. Restart the proxy to apply the new
                            configurations.
                        </div>
                    @endif
                    <x-forms.input placeholder="https://app.coolify.io" id="redirect_url" label="Default Redirect 404"
                        helper="All urls that has no service available will be redirected to this domain." />
                    <div wire:loading wire:target="loadProxyConfiguration" class="pt-4">
                        <x-loading text="Loading proxy configuration..." />
                    </div>
                    <div wire:loading.remove wire:target="loadProxyConfiguration">
                        @if ($proxy_settings)
                            <div class="flex flex-col gap-2 pt-4">
                                <x-forms.textarea label="Configuration file" name="proxy_settings"
                                    wire:model="proxy_settings" rows="30" />
                                <x-forms.button wire:click.prevent="reset_proxy_configuration">
                                    Reset configuration to default
                                </x-forms.button>
                            </div>
                        @endif
                    </div>
                </form>
            @elseif($selectedProxy === 'NONE')
                <div class="flex items-center gap-2">
                    <h2>Configuration</h2>
                    <x-forms.button wire:click.prevent="change_proxy">Switch Proxy</x-forms.button>
                </div>
                <div class="pt-2 pb-4">Custom (None) Proxy Selected</div>
            @else
                <div class="flex items-center gap-2">
                    <h2>Configuration</h2>
                    <x-forms.button wire:click.prevent="change_proxy">Switch Proxy</x-forms.button>
                </div>
            @endif
        @else
            <div>
                <h2>Configuration</h2>
                <div class="subtitle">Select a proxy you would like to use on this server.</div>
                <div class="grid gap-4">
                    <x-forms.button class="box" wire:click="select_proxy('NONE')">
                        Custom (None)
                    </x-forms.button>
                    <x-forms.button class="box" wire:click="select_proxy('TRAEFIK_V2')">
                        Traefik
                    </x-forms.button>
                    <x-forms.button class="box" wire:click="select_proxy('CADDY')">
                        Caddy (experimental)
                    </x-forms.button>
                    <x-forms.button disabled class="box">
                        Nginx
                    </x-forms.button>
                </div>
            </div>
    @endif
</div>
