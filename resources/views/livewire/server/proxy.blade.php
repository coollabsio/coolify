@php use App\Enums\ProxyTypes; @endphp
<div>
    @if ($server->proxyType())
        <div x-init="$wire.loadProxyConfiguration">
            @if ($selectedProxy !== 'NONE')
                <form wire:submit='submit'>
                    <div class="flex items-center gap-2">
                        <h2>Configuration</h2>
                        @if ($server->proxy->status === 'exited' || $server->proxy->status === 'removing')
                            <x-forms.button wire:click.prevent="changeProxy">Switch Proxy</x-forms.button>
                        @else
                            <x-forms.button disabled wire:click.prevent="changeProxy">Switch Proxy</x-forms.button>
                        @endif
                        <x-forms.button type="submit">Save</x-forms.button>

                    </div>
                    <div class="pb-4 "> <svg class="inline-flex w-6 h-6 mr-2 dark:text-warning" viewBox="0 0 256 256"
                            xmlns="http://www.w3.org/2000/svg">
                            <path fill="currentColor"
                                d="M240.26 186.1L152.81 34.23a28.74 28.74 0 0 0-49.62 0L15.74 186.1a27.45 27.45 0 0 0 0 27.71A28.31 28.31 0 0 0 40.55 228h174.9a28.31 28.31 0 0 0 24.79-14.19a27.45 27.45 0 0 0 .02-27.71m-20.8 15.7a4.46 4.46 0 0 1-4 2.2H40.55a4.46 4.46 0 0 1-4-2.2a3.56 3.56 0 0 1 0-3.73L124 46.2a4.77 4.77 0 0 1 8 0l87.44 151.87a3.56 3.56 0 0 1 .02 3.73M116 136v-32a12 12 0 0 1 24 0v32a12 12 0 0 1-24 0m28 40a16 16 0 1 1-16-16a16 16 0 0 1 16 16" />
                        </svg>Before switching proxies, please read <a class="underline dark:text-white"
                            href="https://coolify.io/docs/knowledge-base/server/proxies#switch-between-proxies">this</a>.
                    </div>
                    <h4>Advanced</h4>
                    <div class="pb-4 w-96">
                        <x-forms.checkbox
                            helper="If set, all resources will only have docker container labels for {{ str($server->proxyType())->title() }}.<br>For applications, labels needs to be regenerated manually. <br>Resources needs to be restarted."
                            id="server.settings.generate_exact_labels"
                            label="Generate labels only for {{ str($server->proxyType())->title() }}" instantSave />
                        <x-forms.checkbox instantSave="instantSaveRedirect" id="redirect_enabled"
                            label="Override default request handler"
                            helper="Requests to unknown hosts or stopped services will recieve a 503 response or be redirected to the URL you set below (need to enable this first)." />
                        @if ($redirect_enabled)
                            <x-forms.input placeholder="https://app.coolify.io" id="redirect_url"
                                label="Redirect to (optional)" />
                        @endif
                    </div>
                    @if ($server->proxyType() === ProxyTypes::TRAEFIK->value)
                        <h4>Traefik</h4>
                    @elseif ($server->proxyType() === 'CADDY')
                        <h4>Caddy</h4>
                    @endif
                    @if (
                        $server->proxy->last_applied_settings &&
                            $server->proxy->last_saved_settings !== $server->proxy->last_applied_settings)
                        <div class="text-red-500 ">Configuration out of sync. Restart the proxy to apply the new
                            configurations.
                        </div>
                    @endif
                    <div wire:loading wire:target="loadProxyConfiguration" class="pt-4">
                        <x-loading text="Loading proxy configuration..." />
                    </div>
                    <div wire:loading.remove wire:target="loadProxyConfiguration">
                        @if ($proxy_settings)
                            <div class="flex flex-col gap-2 pt-4">
                                <x-forms.textarea useMonacoEditor monacoEditorLanguage="yaml" label="Configuration file"
                                    name="proxy_settings" id="proxy_settings" rows="30" />
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
                    <x-forms.button wire:click.prevent="changeProxy">Switch Proxy</x-forms.button>
                </div>
                <div class="pt-2 pb-4">Custom (None) Proxy Selected</div>
            @else
                <div class="flex items-center gap-2">
                    <h2>Configuration</h2>
                    <x-forms.button wire:click.prevent="changeProxy">Switch Proxy</x-forms.button>
                </div>
            @endif
        @else
            <div>
                <h2>Configuration</h2>
                <div class="subtitle">Select a proxy you would like to use on this server.</div>
                <div class="grid gap-4">
                    <x-forms.button class="box" wire:click="selectProxy('NONE')">
                        Custom (None)
                    </x-forms.button>
                    <x-forms.button class="box" wire:click="selectProxy('TRAEFIK')">
                        Traefik
                    </x-forms.button>
                    <x-forms.button class="box" wire:click="selectProxy('CADDY')">
                        Caddy
                    </x-forms.button>
                    {{-- <x-forms.button disabled class="box">
                        Nginx
                    </x-forms.button> --}}
                </div>
            </div>
    @endif
</div>
