@php use App\Enums\ProxyTypes; @endphp
<div>
    @if ($server->proxyType())
        <div x-init="$wire.loadProxyConfiguration">
            @if ($selectedProxy !== 'NONE')
                <form wire:submit='submit'>
                    <div class="flex items-center gap-2">
                        <h2>Configuration</h2>
                        @if ($server->proxy->status === 'exited' || $server->proxy->status === 'removing')
                            @can('update', $server)
                                <x-modal-confirmation title="Confirm Proxy Switching?" buttonTitle="Switch Proxy"
                                    submitAction="changeProxy" :actions="['Custom proxy configurations may be reset to their default settings.']"
                                    warningMessage="This operation may cause issues. Please refer to the guide <a href='https://coolify.io/docs/knowledge-base/server/proxies#switch-between-proxies' target='_blank' class='underline text-white'>switching between proxies</a> before proceeding!"
                                    step2ButtonText="Switch Proxy" :confirmWithText="false" :confirmWithPassword="false">
                                </x-modal-confirmation>
                            @endcan
                        @else
                            <x-forms.button canGate="update" :canResource="$server"
                                wire:click="$dispatch('error', 'Currently running proxy must be stopped before switching proxy')">Switch
                                Proxy</x-forms.button>
                        @endif
                        <x-forms.button canGate="update" :canResource="$server" type="submit">Save</x-forms.button>
                    </div>
                    <div class="subtitle">Configure your proxy settings and advanced options.</div>
                    <h3>Advanced</h3>
                    <div class="pb-6 w-96">
                        <x-forms.checkbox canGate="update" :canResource="$server"
                            helper="If set, all resources will only have docker container labels for {{ str($server->proxyType())->title() }}.<br>For applications, labels needs to be regenerated manually. <br>Resources needs to be restarted."
                            id="server.settings.generate_exact_labels"
                            label="Generate labels only for {{ str($server->proxyType())->title() }}" instantSave />
                        <x-forms.checkbox canGate="update" :canResource="$server" instantSave="instantSaveRedirect"
                            id="redirectEnabled" label="Override default request handler"
                            helper="Requests to unknown hosts or stopped services will receive a 503 response or be redirected to the URL you set below (need to enable this first)." />
                        @if ($redirectEnabled)
                            <x-forms.input canGate="update" :canResource="$server" placeholder="https://app.coolify.io"
                                id="redirectUrl" label="Redirect to (optional)" />
                        @endif
                    </div>
                    @php
                        $proxyTitle =
                            $server->proxyType() === ProxyTypes::TRAEFIK->value
                                ? 'Traefik (Coolify Proxy)'
                                : 'Caddy (Coolify Proxy)';
                    @endphp
                    @if ($server->proxyType() === ProxyTypes::TRAEFIK->value || $server->proxyType() === 'CADDY')
                        <div class="flex items-center gap-2">
                            <h3>{{ $proxyTitle }}</h3>
                            @if ($proxySettings)
                                @can('update', $server)
                                    <x-modal-confirmation title="Reset Proxy Configuration?"
                                        buttonTitle="Reset Configuration" submitAction="resetProxyConfiguration"
                                        :actions="[
                                            'Reset proxy configuration to default settings',
                                            'All custom configurations will be lost',
                                            'Custom ports and entrypoints will be removed',
                                        ]" confirmationText="{{ $server->name }}"
                                        confirmationLabel="Please confirm by entering the server name below"
                                        shortConfirmationLabel="Server Name" step2ButtonText="Reset Configuration"
                                        :confirmWithPassword="false" :confirmWithText="true">
                                    </x-modal-confirmation>
                                @endcan
                            @endif
                        </div>
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
                        @if ($proxySettings)
                            <div class="flex flex-col gap-2 pt-2">
                                <x-forms.textarea canGate="update" :canResource="$server" useMonacoEditor
                                    monacoEditorLanguage="yaml"
                                    label="Configuration file ( {{ $this->configurationFilePath }} )"
                                    name="proxySettings" id="proxySettings" rows="30" />
                            </div>
                        @endif
                    </div>
                </form>
            @elseif($selectedProxy === 'NONE')
                <div class="flex items-center gap-2">
                    <h2>Configuration</h2>
                    @can('update', $server)
                        <x-forms.button wire:click.prevent="changeProxy">Switch Proxy</x-forms.button>
                    @endcan
                </div>
                <div class="pt-2 pb-4">Custom (None) Proxy Selected</div>
            @else
                <div class="flex items-center gap-2">
                    <h2>Configuration</h2>
                    @can('update', $server)
                        <x-forms.button wire:click.prevent="changeProxy">Switch Proxy</x-forms.button>
                    @endcan
                </div>
            @endif
        @else
            <div>
                <h2>Configuration</h2>
                <div class="subtitle">Select a proxy you would like to use on this server.</div>
                @can('update', $server)
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
                @else
                    <x-callout type="warning" title="Permission Required" class="mb-4">
                        You don't have permission to configure proxy settings for this server.
                    </x-callout>
                @endcan
            </div>
    @endif
</div>
