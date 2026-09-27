<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Cloudflare Tunnel | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="cloudflare-tunnel" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            <x-application.settings-section id="server-cloudflare-http-section" title="HTTP origin"
                helper="Publish HTTP apps through Cloudflare Tunnel without opening ports 80/443 or pointing DNS at this server’s public IP. Coolify domains stay HTTP; Cloudflare terminates TLS.">
                <x-slot:actions>
                    <x-status-badge :status="$isCloudflareHttpTunnelEnabled ? 'Enabled' : 'Disabled'"
                        :type="$isCloudflareHttpTunnelEnabled ? 'success' : 'neutral'" />
                </x-slot:actions>

                <x-callout type="info" title="No port forward or public A record">
                    Visitors reach Cloudflare over HTTPS. Point DNS at the tunnel CNAME, not this server’s WAN IP.
                    Leave router ports 80/443 closed. Coolify domains stay HTTP with Redirect HTTP to HTTPS off.
                </x-callout>

                @can('update', $server)
                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <x-forms.input id="httpCname" label="Tunnel CNAME"
                            placeholder="xxxxxxxx-xxxx.cfargotunnel.com"
                            helper="Optional. Used by Recheck as the expected CNAME. Recheck also accepts any cfargotunnel.com target or Cloudflare proxy IPs." />
                    </div>
                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                        @if ($isCloudflareHttpTunnelEnabled)
                            <x-modal-confirmation title="Disable HTTP origin?"
                                buttonTitle="Disable HTTP origin" isErrorButton submitAction="disableHttpOrigin"
                                :actions="[
                                    'New domains will use Direct mode again (HTTPS, Let’s Encrypt, A records).',
                                    'Existing domains are not rewritten automatically.',
                                ]" confirmationText="DISABLE HTTP ORIGIN"
                                confirmationLabel="Type the confirmation text to disable HTTP origin."
                                shortConfirmationLabel="Confirmation text" />
                        @endif
                        <x-forms.button type="button" wire:click="saveHttpOrigin" isHighlighted>
                            {{ $isCloudflareHttpTunnelEnabled ? 'Save HTTP origin' : 'Enable HTTP origin' }}
                        </x-forms.button>
                    </div>
                @endcan
            </x-application.settings-section>

            @unless ($server->isLocalhost())
                <x-application.settings-section id="server-cloudflare-overview-section" title="SSH"
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
                            <x-process-dialog @automated.window="processDialogOpen = true" closeWithX size="xl">
                                <x-slot:title>Cloudflare Tunnel Configuration</x-slot:title>
                                <x-slot:content>
                                    <livewire:activity-monitor header="Logs" fullHeight />
                                </x-slot:content>
                            </x-process-dialog>
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
            @endunless
        </div>
    </div>
</div>
