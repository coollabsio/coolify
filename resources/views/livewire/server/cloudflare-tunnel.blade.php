<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Cloudflare Tunnel | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="cloudflare-tunnel" />
        <div class="w-full">
            <div class="flex flex-col">
                <div class="flex gap-2 items-center">
                    <h2>{{ __('server.cloudflare_tunnel') }}</h2>
                    <x-helper class="inline-flex"
                        helper="{{ __('server.cloudflare_tunnel_helper') }}" />
                    @if ($isCloudflareTunnelsEnabled)
                        <span
                            class="px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded dark:text-green-100 dark:bg-green-800">
                            {{ __('server.enabled') }}
                        </span>
                    @endif
                </div>
                <div>{{ __('server.secure_servers_cloudflare') }}</div>
            </div>
            <div class="flex flex-col gap-2 pt-6">
                @if ($isCloudflareTunnelsEnabled)
                    <div class="flex flex-col gap-4">
                        <x-callout type="warning" title="{{ __('server.warning') }}">
                            {{ __('server.disable_cloudflare_warning') }}
                        </x-callout>
                        <div class="w-64">
                            @if ($server->ip_previous)
                                <x-modal-confirmation title="{{ __('server.confirm_disable_cloudflare_tunnel') }}"
                                    buttonTitle="{{ __('server.disable_cloudflare_tunnel') }}" isErrorButton
                                    submitAction="toggleCloudflareTunnels" :actions="[
                                        __('server.disable_cloudflare_tunnel_action_1'),
                                        __('server.disable_cloudflare_tunnel_action_2'),
                                    ]"
                                    confirmationText="DISABLE CLOUDFLARE TUNNEL"
                                    confirmationLabel="{{ __('modal.disable_cloudflare_tunnel_confirmation') }}"
                                    shortConfirmationLabel="{{ __('modal.confirmation_text') }}" />
                            @else
                                <x-modal-confirmation title="{{ __('server.confirm_disable_cloudflare_tunnel') }}"
                                    buttonTitle="{{ __('server.disable_cloudflare_tunnel') }}" isErrorButton
                                    submitAction="toggleCloudflareTunnels" :actions="[
                                        __('server.disable_cloudflare_tunnel_action_1'),
                                        __('server.disable_cloudflare_tunnel_action_2'),
                                        'The server may become inaccessible if the IP address is not updated correctly.',
                                        'SSH access will revert to the standard port configuration.',
                                    ]"
                                    confirmationText="DISABLE CLOUDFLARE TUNNEL"
                                    confirmationLabel="{{ __('modal.disable_cloudflare_tunnel_confirmation') }}"
                                    shortConfirmationLabel="{{ __('modal.confirmation_text') }}" />
                            @endif

                        </div>
                    </div>
                @elseif (!$server->isFunctional())
                    <x-callout type="info" title="{{ __('server.configuration_options') }}" class="mb-4">
                        {!! __('server.auto_config_desc') !!}
                        <br />
                        {!! __('server.manual_config_desc') !!}
                        <br /><br />
                        {!! __('server.for_more_info') !!}
                    </x-callout>
                @endif
                @if (!$isCloudflareTunnelsEnabled && $server->isFunctional())
                    <div class="flex  flex-col pb-2">
                        <h3>{{ __('server.automated') }} </h3>
                        <a href="https://coolify.io/docs/knowledge-base/cloudflare/tunnels/server-ssh" target="_blank"
                            class="text-xs underline hover:text-warning-600 dark:hover:text-warning-200">{{ __('menu.documentation') }}<x-external-link /></a>
                    </div>
                    <div class="flex gap-2">
                        <x-slide-over @automated.window="slideOverOpen = true" fullScreen>
                            <x-slot:title>{{ __('server.cloudflare_tunnel_configuration') }}</x-slot:title>
                            <x-slot:content>
                                <livewire:activity-monitor header="{{ __('server.logs') }}" fullHeight />
                            </x-slot:content>
                        </x-slide-over>
                        @can('update', $server)
                            <form @submit.prevent="$wire.dispatch('automatedCloudflareConfig')"
                                class="flex flex-col gap-2 w-full">
                                <x-forms.input id="cloudflare_token" required label="{{ __('server.cloudflare_token') }}" type="password" />
                                <x-forms.input id="ssh_domain" label="{{ __('server.configured_ssh_domain') }}" required
                                    helper="{{ __('server.configured_ssh_domain_helper') }}" />
                                <x-forms.button type="submit" isHighlighted>{{ __('common.continue') }}</x-forms.button>
                            </form>
                        @else
                            <x-callout type="warning" title="{{ __('server.permission_required') }}" class="mb-4">
                                {{ __('server.no_permission_configure_cloudflare') }}
                            </x-callout>
                        @endcan
                    </div>
                    @script
                        <script>
                            $wire.$on('automatedCloudflareConfig', () => {
                                try {
                                    window.dispatchEvent(new CustomEvent('automated'));
                                    $wire.$call('automatedCloudflareConfig');
                                } catch (error) {
                                    console.error(error);
                                }
                            });
                        </script>
                    @endscript
            </div>
            <h3 class="pt-6 pb-2">{{ __('server.manual') }}</h3>
            <div class="pl-2">
                @can('update', $server)
                    <x-modal-confirmation buttonFullWidth title="{{ __('server.confirm_manually_configured_cloudflare_tunnel') }}"
                        buttonTitle="{{ __('server.manually_configured_cloudflare_tunnel') }}" submitAction="manualCloudflareConfig"
                        :actions="[
                            __('server.manual_config_actions_1'),
                            __('server.manual_config_actions_2'),
                        ]" confirmationText="{{ __('server.i_manually_configured') }}"
                        confirmationLabel="{{ __('modal.manually_configured_cloudflare_tunnel_confirmation') }}"
                        shortConfirmationLabel="{{ __('modal.confirmation_text') }}" />
                @else
                    <x-callout type="warning" title="{{ __('server.permission_required') }}" class="mb-4">
                        {{ __('server.no_permission_configure_cloudflare') }}
                    </x-callout>
                @endcan
            </div>
            @endif
        </div>
    </div>
</div>
