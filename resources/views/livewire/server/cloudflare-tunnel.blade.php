<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Cloudflare Tunnel | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="cloudflare-tunnel" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            <x-process-dialog @automated.window="processDialogOpen = true"
                @automated-http.window="processDialogOpen = true" closeWithX size="xl">
                <x-slot:title>Cloudflare Tunnel Configuration</x-slot:title>
                <x-slot:content>
                    <livewire:activity-monitor header="Logs" fullHeight />
                </x-slot:content>
            </x-process-dialog>

            <x-application.settings-section id="server-cloudflare-http-section" title="HTTP origin"
                helper="Publish HTTP apps through Cloudflare Tunnel without opening ports 80/443 or pointing DNS at this server’s public IP. Coolify domains stay HTTP; Cloudflare terminates TLS.">
                <x-slot:actions>
                    <x-status-badge :status="$isCloudflareHttpTunnelEnabled ? 'Enabled' : 'Disabled'"
                        :type="$isCloudflareHttpTunnelEnabled ? 'success' : 'neutral'" />
                </x-slot:actions>

                @if (!$proxyRunning)
                    <x-callout type="warning" title="Coolify proxy is not running">
                        Cloudflare Tunnel forwards to <code>http://127.0.0.1:80</code>. Start the proxy on this
                        server before the tunnel can reach applications.
                    </x-callout>
                @endif

                <x-callout type="info" title="No port forward or public A record">
                    Visitors reach Cloudflare over HTTPS. cloudflared dials Cloudflare outbound, then Coolify’s
                    proxy on port 80. Do not point DNS at this server’s WAN IP, and leave router ports 80/443
                    closed. Coolify domains stay <strong>http://</strong> with Redirect HTTP to HTTPS disabled.
                </x-callout>

                @if ($isCloudflareHttpTunnelEnabled)
                    <div class="mt-4 grid gap-3 text-sm text-neutral-600 dark:text-fg-dim">
                        @if (filled($httpCname))
                            <p>DNS target: <code class="font-mono">{{ $httpCname }}</code></p>
                        @endif
                        @if (filled($httpHostname))
                            <p>Catch-all hostname: <code class="font-mono">{{ $httpHostname }}</code></p>
                        @endif
                        @if (filled($httpDashboardHostname))
                            <p>Dashboard hostname: <code class="font-mono">{{ $httpDashboardHostname }}</code></p>
                        @endif
                        @if (filled($httpLastSeenAt))
                            <p>Connector last seen: {{ $httpLastSeenAt }}</p>
                        @endif
                    </div>
                    <x-callout type="warning" class="mt-4" title="Protect the dashboard">
                        If you expose Coolify through this tunnel, put
                        <a class="underline" href="https://developers.cloudflare.com/cloudflare-one/policies/access/"
                            target="_blank" rel="noopener noreferrer">Cloudflare Access</a>
                        in front of the hostname. Do not publish port 8000 to the internet without it.
                    </x-callout>
                    <div class="mt-4">
                        <x-modal-confirmation title="Disable HTTP origin?"
                            buttonTitle="Disable HTTP origin" isErrorButton
                            submitAction="disableHttpOrigin" :actions="[
                                'New domains on this server will use Direct mode again (A records and Let’s Encrypt).',
                                'The Coolify-managed HTTP cloudflared container is removed unless you chose an existing connector.',
                            ]"
                            confirmationText="DISABLE HTTP ORIGIN"
                            confirmationLabel="Type the confirmation text to disable HTTP origin."
                            shortConfirmationLabel="Confirmation text" />
                    </div>
                @else
                    <p class="text-sm leading-6 text-neutral-600 dark:text-fg-dim">
                        Token scopes: Account → Cloudflare Tunnel → Edit; Account Settings → Read; Zone → DNS →
                        Edit; Zone → Read. Tokens that start with <code>eyJ</code> are stored encrypted and never
                        shown in logs.
                    </p>
                    @if ($existingConnectorDetected)
                        <x-callout type="info" title="cloudflared is already running">
                            Coolify detected an existing connector. Use that tunnel token instead of installing a
                            second connector for the same tunnel.
                        </x-callout>
                    @endif
                    @cannot('update', $server)
                        <x-callout type="danger" title="Insufficient permissions">
                            You do not have permission to configure Cloudflare Tunnel for this server.
                        </x-callout>
                    @else
                        <form wire:submit="enableHttpOrigin" class="mt-4 flex flex-col gap-4">
                            <x-forms.input id="httpApiToken" label="Cloudflare API token" type="password"
                                helper="Used to create or attach a remotely-managed tunnel, set catch-all ingress to http://127.0.0.1:80, and create missing proxied CNAMEs." />
                            <div>
                                <x-forms.button type="button" wire:click="loadCloudflareAccounts">
                                    List accounts and zones
                                </x-forms.button>
                            </div>
                            @if ($httpAccounts !== [])
                                <x-forms.listbox id="httpAccountId" label="Account"
                                    :options="collect($httpAccounts)->map(fn ($account) => ['value' => $account['id'], 'label' => $account['name']])->all()" />
                            @else
                                <x-forms.input id="httpAccountId" label="Account ID" />
                            @endif
                            @if ($httpZones !== [])
                                <x-forms.listbox id="httpZoneId" label="Zone"
                                    :options="collect($httpZones)->map(fn ($zone) => ['value' => $zone['id'], 'label' => $zone['name']])->all()" />
                            @else
                                <x-forms.input id="httpZoneId" label="Zone ID"
                                    helper="Required only when Coolify should create missing CNAMEs." />
                            @endif
                            @if ($httpTunnels !== [])
                                <x-forms.listbox id="httpTunnelId" label="Existing tunnel"
                                    :options="array_merge(
                                        [['value' => '', 'label' => 'Create a new tunnel']],
                                        collect($httpTunnels)->map(fn ($tunnel) => ['value' => $tunnel['id'], 'label' => $tunnel['name'].' ('.$tunnel['cname'].')'])->all(),
                                    )" />
                            @else
                                <x-forms.input id="httpTunnelId" label="Existing tunnel ID"
                                    helper="Leave empty to create a new remotely-managed tunnel." />
                            @endif
                            <x-forms.input id="httpHostname" required label="Published hostname or wildcard"
                                placeholder="*.example.com"
                                helper="Catch-all ingress to the Coolify proxy. Also add the apex hostname when you use a wildcard." />
                            <x-forms.input id="httpDashboardHostname"
                                label="Expose Coolify dashboard through this tunnel"
                                placeholder="coolify.example.com"
                                helper="Optional. Routes this hostname to localhost:{{ config('app.port', 8000) }} so GitHub App callbacks can use Settings → Instance Domain. Put Cloudflare Access in front of it." />
                            <x-forms.checkbox id="installHttpConnector"
                                label="Install Coolify-managed cloudflared (host network)"
                                helper="Required unless cloudflared already runs on this host with the same tunnel." />
                            <x-forms.checkbox id="createMissingDnsRecords"
                                label="Create missing proxied CNAMEs"
                                helper="Creates CNAME records to {{ $cnameTarget }} only when the name is unused. Existing records are never overwritten." />
                            <div class="flex flex-wrap justify-end gap-2">
                                <x-forms.button type="submit" isHighlighted>Enable HTTP origin</x-forms.button>
                            </div>
                        </form>
                        <div class="mt-4">
                            <x-modal-confirmation title="Mark HTTP origin as already configured?"
                                buttonTitle="I already run cloudflared"
                                submitAction="markHttpOriginManually" :actions="[
                                    'cloudflared already forwards HTTP to the Coolify proxy on this server.',
                                    'Coolify will stop recommending WAN A records and sslip.io for this server.',
                                    'New domains default to HTTP with Redirect HTTP to HTTPS disabled.',
                                ]" confirmationText="HTTP ORIGIN IS READY"
                                confirmationLabel="Type the confirmation text to continue."
                                shortConfirmationLabel="Confirmation text" />
                        </div>
                    @endcannot
                @endif
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
                    <x-application.settings-section id="server-cloudflare-automated-section" title="SSH automated setup"
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

                    <x-application.settings-section id="server-cloudflare-manual-section" title="SSH manual setup"
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
                @endif
            @endunless
        </div>
    </div>

    @script
        <script>
            $wire.$on('automatedCloudflareConfig', () => {
                window.dispatchEvent(new CustomEvent('automated'));
                $wire.$call('automatedCloudflareConfig');
            });
            $wire.$on('http-origin-started', () => {
                window.dispatchEvent(new CustomEvent('automated-http'));
            });
        </script>
    @endscript
</div>
