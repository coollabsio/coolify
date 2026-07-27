<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Cloudflare Tunnel | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-8 grid w-full max-w-[1180px] min-w-0 gap-8 xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-server.sidebar :server="$server" activeMenu="cloudflare-tunnel" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            <x-application.settings-section id="server-cloudflare-overview-section" title="Cloudflare Tunnel"
                helper="Proxy SSH traffic through Cloudflare so the server SSH port can remain closed.">
                <x-slot:actions>
                    <x-status-badge :status="$isCloudflareTunnelsEnabled ? 'Enabled' : 'Disabled'"
                        :type="$isCloudflareTunnelsEnabled ? 'success' : 'neutral'" />
                </x-slot:actions>

                @if ($isCloudflareTunnelsEnabled)
                    <x-callout type="warning" title="Disabling the tunnel can interrupt server access">
                        The server IP must be restored to its direct address after disabling the tunnel.
                    </x-callout>
                    <div class="mt-4">
                        <x-modal-confirmation title="Disable Cloudflare Tunnel?"
                            buttonTitle="Disable Cloudflare Tunnel" isErrorButton
                            submitAction="toggleCloudflareTunnels" :actions="$server->ip_previous
                                ? [
                                    'Cloudflare Tunnel will be disabled for this server.',
                                    'The server IP address will be restored to its previous value.',
                                ]
                                : [
                                    'Cloudflare Tunnel will be disabled for this server.',
                                    'You must manually restore the direct server IP address.',
                                    'The server may become inaccessible until the IP is corrected.',
                                ]"
                            confirmationText="DISABLE CLOUDFLARE TUNNEL"
                            confirmationLabel="Type the confirmation text to disable Cloudflare Tunnel."
                            shortConfirmationLabel="Confirmation text" />
                    </div>
                @elseif (!$server->isFunctional())
                    <x-callout type="info" title="Validate the server for automated setup">
                        Automated configuration requires a validated server, a Cloudflare token, and an SSH domain.
                        You can also
                        <button type="button" wire:click="manualCloudflareConfig" class="font-medium underline">
                            mark a manual configuration as complete
                        </button>.
                    </x-callout>
                @else
                    <p class="text-sm leading-6 text-neutral-600 dark:text-fg-dim">
                        Choose automated setup to install and configure cloudflared, or confirm that you already
                        configured the tunnel manually.
                    </p>
                @endif
            </x-application.settings-section>

            @if (!$isCloudflareTunnelsEnabled && $server->isFunctional())
                <x-application.settings-section id="server-cloudflare-automated-section" title="Automated setup"
                    helper="Let Coolify configure the Cloudflare SSH tunnel on this server.">
                    <x-slot:actions>
                        <a class="button"
                            href="https://coolify.io/docs/knowledge-base/cloudflare/tunnels/server-ssh"
                            target="_blank">
                            Documentation
                            <x-external-link />
                        </a>
                    </x-slot:actions>

                    @cannot('update', $server)
                        <x-callout type="danger" title="Insufficient permissions">
                            You do not have permission to configure Cloudflare Tunnel for this server.
                        </x-callout>
                    @else
                        <x-slide-over @automated.window="slideOverOpen = true" fullScreen>
                            <x-slot:title>Cloudflare Tunnel Configuration</x-slot:title>
                            <x-slot:content>
                                <livewire:activity-monitor header="Logs" fullHeight />
                            </x-slot:content>
                        </x-slide-over>
                        <form @submit.prevent="$wire.dispatch('automatedCloudflareConfig')">
                            <div class="grid gap-4 lg:grid-cols-2">
                                <x-forms.input id="cloudflare_token" required label="Cloudflare token"
                                    type="password" />
                                <x-forms.input id="ssh_domain" label="SSH domain" required
                                    helper="Enter the hostname configured in Cloudflare without a protocol." />
                            </div>
                            <div class="mt-4 flex justify-end">
                                <x-forms.button type="submit" isHighlighted>Configure tunnel</x-forms.button>
                            </div>
                        </form>
                    @endcannot
                </x-application.settings-section>

                <x-application.settings-section id="server-cloudflare-manual-section" title="Manual setup"
                    helper="Use this only after cloudflared and the Cloudflare tunnel are already configured.">
                    @can('update', $server)
                        <x-modal-confirmation title="Confirm manual Cloudflare Tunnel configuration"
                            buttonTitle="I configured the tunnel manually"
                            submitAction="manualCloudflareConfig" :actions="[
                                'Cloudflare and cloudflared have already been configured.',
                                'An incomplete setup can make the server unreachable.',
                            ]" confirmationText="I manually configured Cloudflare Tunnel"
                            confirmationLabel="Type the confirmation text to continue."
                            shortConfirmationLabel="Confirmation text" />
                    @endcan
                </x-application.settings-section>

                @script
                    <script>
                        $wire.$on('automatedCloudflareConfig', () => {
                            window.dispatchEvent(new CustomEvent('automated'));
                            $wire.$call('automatedCloudflareConfig');
                        });
                    </script>
                @endscript
            @endif
        </div>
    </div>
</div>
