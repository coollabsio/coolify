@php use App\Enums\ProxyTypes; @endphp
<div>
    @if ($server->proxyType())
        <div x-init="$wire.loadProxyConfiguration">
            @if ($selectedProxy !== 'NONE')
                <form wire:submit='submit'>
                    <div class="flex items-center gap-2">
                        <h2>{{ __('common.configuration') }}</h2>
                        @if ($server->proxy->status === 'exited' || $server->proxy->status === 'removing')
                            @can('update', $server)
                                <x-modal-confirmation title="{{ __('server.confirm_proxy_switch') }}" buttonTitle="{{ __('server.switch_proxy') }}"
                                    submitAction="changeProxy" :actions="[__('server.proxy_switch_warning')]"
                                    warningMessage="{!! __('server.proxy_switch_warning_2') !!}"
                                    step2ButtonText="{{ __('server.switch_proxy') }}" :confirmWithText="false" :confirmWithPassword="false">
                                </x-modal-confirmation>
                            @endcan
                        @else
                            <x-forms.button canGate="update" :canResource="$server"
                                wire:click="$dispatch('error', 'Currently running proxy must be stopped before switching proxy')">{{ __('server.switch_proxy') }}</x-forms.button>
                        @endif
                        <x-forms.button canGate="update" :canResource="$server" type="submit">{{ __('common.save') }}</x-forms.button>
                    </div>
                    <div class="pb-4">{{ __('server.proxy_settings_desc') }}</div>
                    @if (
                        $server->proxy->last_applied_settings &&
                            $server->proxy->last_saved_settings !== $server->proxy->last_applied_settings)
                        <x-callout type="warning" title="{{ __('server.config_out_of_sync') }}" class="my-4">
                            {{ __('server.proxy_config_out_of_sync_desc') }}
                        </x-callout>
                    @endif
                    <h3>{{ __('common.advanced') }}</h3>
                    <div class="pb-6 w-96">
                        <x-forms.checkbox canGate="update" :canResource="$server"
                            helper="{!! __('server.generate_exact_labels_helper', ['proxy' => str($server->proxyType())->title()]) !!}"
                            id="generateExactLabels"
                            label="{{ __('server.generate_exact_labels', ['proxy' => str($server->proxyType())->title()]) }}" instantSave />
                        <x-forms.checkbox canGate="update" :canResource="$server" instantSave="instantSaveRedirect"
                            id="redirectEnabled" label="{{ __('server.override_request_handler') }}"
                            helper="{{ __('server.override_request_handler_helper') }}" />
                        @if ($redirectEnabled)
                            <x-forms.input canGate="update" :canResource="$server" placeholder="https://app.coolify.io"
                                id="redirectUrl" label="{{ __('server.redirect_to') }}" />
                        @endif
                    </div>
                    @php
                        $proxyTitle =
                            $server->proxyType() === ProxyTypes::TRAEFIK->value
                                ? 'Traefik (Coolify Proxy)'
                                : 'Caddy (Coolify Proxy)';
                    @endphp
                    @if ($server->proxyType() === ProxyTypes::TRAEFIK->value || $server->proxyType() === 'CADDY')
                        <div @if($server->proxyType() === ProxyTypes::TRAEFIK->value) x-data="{ traefikWarningsDismissed: localStorage.getItem('callout-dismissed-traefik-warnings-{{ $server->id }}') === 'true' }" @endif>
                            <div class="flex items-center gap-2">
                                <h3>{{ $proxyTitle }}</h3>
                                @can('update', $server)
                                    <div wire:loading wire:target="loadProxyConfiguration">
                                        <x-forms.button disabled>{{ __('server.reset_configuration') }}</x-forms.button>
                                    </div>
                                    <div wire:loading.remove wire:target="loadProxyConfiguration">
                                        @if ($proxySettings)
                                            <x-modal-confirmation title="{{ __('server.confirm_reset_config') }}"
                                                buttonTitle="{{ __('server.reset_configuration') }}" submitAction="resetProxyConfiguration"
                                                :actions="[
                                                    __('server.reset_config_action_1'),
                                                    __('server.reset_config_action_2'),
                                                    __('server.reset_config_action_3'),
                                                ]" confirmationText="{{ $server->name }}"
                                                confirmationLabel="{{ __('server.confirm_label_server_name') }}"
                                                shortConfirmationLabel="{{ __('server.server_name_label') }}" step2ButtonText="{{ __('server.reset_configuration') }}"
                                                :confirmWithPassword="false" :confirmWithText="true">
                                            </x-modal-confirmation>
                                        @endif
                                    </div>
                                @endcan
                                @if ($server->proxyType() === ProxyTypes::TRAEFIK->value)
                                    <button type="button" x-show="traefikWarningsDismissed"
                                            @click="traefikWarningsDismissed = false; localStorage.removeItem('callout-dismissed-traefik-warnings-{{ $server->id }}')"
                                            class="p-1.5 rounded hover:bg-warning-100 dark:hover:bg-warning-900/30 transition-colors"
                                            title="Show Traefik warnings">
                                        <svg class="w-4 h-4 text-warning-600 dark:text-warning-400" viewBox="0 0 256 256" xmlns="http://www.w3.org/2000/svg">
                                            <path fill="currentColor" d="M240.26 186.1L152.81 34.23a28.74 28.74 0 0 0-49.62 0L15.74 186.1a27.45 27.45 0 0 0 0 27.71A28.31 28.31 0 0 0 40.55 228h174.9a28.31 28.31 0 0 0 24.79-14.19a27.45 27.45 0 0 0 .02-27.71m-20.8 15.7a4.46 4.46 0 0 1-4 2.2H40.55a4.46 4.46 0 0 1-4-2.2a3.56 3.56 0 0 1 0-3.73L124 46.2a4.77 4.77 0 0 1 8 0l87.44 151.87a3.56 3.56 0 0 1 .02 3.73M116 136v-32a12 12 0 0 1 24 0v32a12 12 0 0 1-24 0m28 40a16 16 0 1 1-16-16a16 16 0 0 1 16 16"></path>
                                        </svg>
                                    </button>
                                @endif
                            </div>
                            @if ($server->proxyType() === ProxyTypes::TRAEFIK->value)
                                <div x-show="!traefikWarningsDismissed"
                                     x-transition:enter="transition ease-out duration-200"
                                     x-transition:enter-start="opacity-0 -translate-y-2"
                                     x-transition:enter-end="opacity-100 translate-y-0"
                                     x-transition:leave="transition ease-in duration-150"
                                     x-transition:leave-start="opacity-100 translate-y-0"
                                     x-transition:leave-end="opacity-0 -translate-y-2">
                                    @if ($server->detected_traefik_version === 'latest')
                                        <x-callout dismissible onDismiss="traefikWarningsDismissed = true; localStorage.setItem('callout-dismissed-traefik-warnings-{{ $server->id }}', 'true')" type="warning" title="{{ __('server.traefik_latest_tag_title') }}" class="my-4">
                                            {!! __('server.traefik_latest_tag_desc', ['version' => $this->latestTraefikVersion]) !!}
                                        </x-callout>
                                    @elseif($this->isTraefikOutdated)
                                        <x-callout dismissible onDismiss="traefikWarningsDismissed = true; localStorage.setItem('callout-dismissed-traefik-warnings-{{ $server->id }}', 'true')" type="warning" title="{{ __('server.traefik_patch_available_title') }}" class="my-4">
                                            {!! __('server.traefik_patch_available_desc', ['current' => $server->detected_traefik_version, 'latest' => $this->latestTraefikVersion]) !!}
                                        </x-callout>
                                    @endif
                                    @if ($this->newerTraefikBranchAvailable)
                                        <x-callout dismissible onDismiss="traefikWarningsDismissed = true; localStorage.setItem('callout-dismissed-traefik-warnings-{{ $server->id }}', 'true')" type="info" title="{{ __('server.traefik_minor_available_title') }}" class="my-4">
                                            {!! __('server.traefik_minor_available_desc', ['new' => $this->newerTraefikBranchAvailable, 'current' => $server->detected_traefik_version]) !!}
                                        </x-callout>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif
                    <div wire:loading wire:target="loadProxyConfiguration" class="pt-4">
                        <x-loading text="{{ __('server.loading_proxy_config') }}" />
                    </div>
                    <div wire:loading.remove wire:target="loadProxyConfiguration">
                        @if ($proxySettings)
                            <div class="flex flex-col gap-2 pt-2">
                                <x-forms.textarea canGate="update" :canResource="$server" useMonacoEditor
                                    monacoEditorLanguage="yaml"
                                    label="{{ __('server.configuration_file', ['path' => $this->configurationFilePath]) }}"
                                    name="proxySettings" id="proxySettings" rows="30" />
                            </div>
                        @endif
                    </div>
                </form>
            @elseif($selectedProxy === 'NONE')
                <div class="flex items-center gap-2">
                    <h2>{{ __('common.configuration') }}</h2>
                    @can('update', $server)
                        <x-forms.button wire:click.prevent="changeProxy">{{ __('server.switch_proxy') }}</x-forms.button>
                    @endcan
                </div>
                <div class="pt-2 pb-4">{{ __('server.custom_none_proxy') }}</div>
            @else
                <div class="flex items-center gap-2">
                    <h2>{{ __('common.configuration') }}</h2>
                    @can('update', $server)
                        <x-forms.button wire:click.prevent="changeProxy">{{ __('server.switch_proxy') }}</x-forms.button>
                    @endcan
                </div>
            @endif
        @else
            <div>
                <h2>{{ __('common.configuration') }}</h2>
                <div class="subtitle">{{ __('server.select_proxy') }}</div>
                @can('update', $server)
                    <div class="grid gap-4">
                        <x-forms.button class="coolbox" wire:click="selectProxy('NONE')">
                            Custom (None)
                        </x-forms.button>
                        <x-forms.button class="coolbox" wire:click="selectProxy('TRAEFIK')">
                            Traefik
                        </x-forms.button>
                        <x-forms.button class="coolbox" wire:click="selectProxy('CADDY')">
                            Caddy
                        </x-forms.button>
                        {{-- <x-forms.button disabled class="box">
                            Nginx
                        </x-forms.button> --}}
                    </div>
                @else
                    <x-callout type="warning" title="{{ __('server.permission_required') }}" class="mb-4">
                        {{ __('server.permission_required_desc') }}
                    </x-callout>
                @endcan
            </div>
    @endif
</div>
