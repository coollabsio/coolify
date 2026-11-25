<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Cloudflare Tunnel | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="cloudflare-tunnel" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            <x-application.settings-section id="server-cloudflare-overview-section" title="Cloudflare Tunnel"
                helper="Proxy SSH traffic through Cloudflare so the server SSH port can remain closed.">
                <x-slot:actions>
                    @if ($isCloudflareTunnelsEnabled && auth()->user()->can('update', $server))
                        <x-forms.button wire:click="updateCloudflareTunnel">Update Cloudflare Tunnel</x-forms.button>
                        <x-process-dialog @cloudflare-tunnel-update-started.window="processDialogOpen = true"
                            closeWithX size="xl">
                            <x-slot:title>Cloudflare Tunnel Update Logs</x-slot:title>
                            <x-slot:content>
                                <livewire:activity-monitor fullHeight />
                            </x-slot:content>
                        </x-process-dialog>
                    @endif
                    @if (!$isCloudflareTunnelsEnabled)
                        <a class="button" href="https://coolify.io/docs/knowledge-base/cloudflare/tunnels/server-ssh"
                            target="_blank">
                            Documentation
                            <x-external-link />
                        </a>
                    @endif
                    @if (!$isCloudflareTunnelsEnabled && $server->isFunctional() && auth()->user()->can('update', $server))
                        <x-modal-confirmation title="Confirm manual Cloudflare Tunnel configuration"
                            buttonTitle="I configured the tunnel manually"
                            submitAction="manualCloudflareConfig" :actions="[
                                'Cloudflare and cloudflared have already been configured.',
                                'An incomplete setup can make the server unreachable.',
                            ]" confirmationText="I manually configured Cloudflare Tunnel"
                            confirmationLabel="Type the confirmation text to continue."
                            shortConfirmationLabel="Confirmation text" />
                    @endif
                    @if ($isCloudflareTunnelsEnabled)
                        <x-status-badge status="Enabled" type="success" />
                    @endif
                </x-slot:actions>

                @if ($isCloudflareTunnelsEnabled)
                    @if (filled($server->settings->cloudflare_tunnel_id))
                        <p class="mb-4 text-sm leading-6 text-neutral-600 dark:text-fg-dim">
                            Coolify manages this tunnel through the Cloudflare API (tunnel
                            <code>{{ \App\Services\CloudflareTunnelService::tunnelName($server) }}</code>).
                        </p>
                    @endif
                    <x-callout type="warning" title="Disabling the tunnel can interrupt server access">
                        The server IP must be restored to its direct address after disabling the tunnel.
                    </x-callout>
                    @can('update', $server)
                        <div class="mt-4">
                            <x-modal-confirmation title="Disable Cloudflare Tunnel?"
                                buttonTitle="Disable Cloudflare Tunnel" isErrorButton
                                submitAction="toggleCloudflareTunnels" :actions="array_merge(
                                    ['Cloudflare Tunnel will be disabled for this server.'],
                                    filled($server->settings->cloudflare_tunnel_id)
                                        ? ['The Cloudflare tunnel and the DNS record that Coolify created will be deleted.']
                                        : [],
                                    $server->ip_previous
                                        ? ['The server IP address will be restored to its previous value.']
                                        : [
                                            'You must manually restore the direct server IP address.',
                                            'The server may become inaccessible until the IP is corrected.',
                                        ],
                                )"
                                confirmationText="DISABLE CLOUDFLARE TUNNEL"
                                confirmationLabel="Type the confirmation text to disable Cloudflare Tunnel."
                                shortConfirmationLabel="Confirmation text" />
                        </div>
                    @endcan
                @elseif (!$server->isFunctional())
                    <x-callout type="info" title="Validate the server for automated setup">
                        Automated configuration requires a validated server, a Cloudflare token, and an SSH domain.
                        You can also
                        <button type="button" wire:click="manualCloudflareConfig" class="font-medium underline">
                            mark a manual configuration as complete
                        </button>.
                    </x-callout>
                @else
                    @cannot('update', $server)
                        <x-callout type="danger" title="Insufficient permissions">
                            You do not have permission to configure Cloudflare Tunnel for this server.
                        </x-callout>
                    @else
                        <x-process-dialog @automated.window="processDialogOpen = true" closeWithX size="xl">
                            <x-slot:title>Cloudflare Tunnel Configuration Logs</x-slot:title>
                            <x-slot:content>
                                <livewire:activity-monitor fullHeight />
                            </x-slot:content>
                        </x-process-dialog>

                        @if ($tunnelTokens->isEmpty())
                            <x-callout type="info" title="No Cloudflare token can manage tunnels">
                                @if ($hasCloudflareTokens)
                                    Enable the Cloudflare Tunnel capability on a Cloudflare token
                                @else
                                    Add a Cloudflare token with the DNS and Cloudflare Tunnel capabilities
                                @endif
                                in
                                <a href="{{ route('security.integration-tokens') }}" class="font-medium underline"
                                    {{ wireNavigate() }}>Integration tokens</a>.
                                The token needs Account - Cloudflare Tunnel - Edit, Zone - DNS - Edit, and Zone - Zone - Read.
                            </x-callout>
                            @can('create', App\Models\IntegrationToken::class)
                                <div class="mt-4">
                                    <x-modal-input title="New Integration Token">
                                        <x-slot:content>
                                            <button type="button" class="button button-highlighted">
                                                <x-reicon name="plus" class="size-3.5" />
                                                Add Cloudflare token
                                            </button>
                                        </x-slot:content>
                                        <livewire:security.integration-token-form :modal_mode="true"
                                            :capabilities="['dns', 'tunnel']" wire:key="new-cloudflare-tunnel-token" />
                                    </x-modal-input>
                                </div>
                            @endcan
                        @else
                            <form wire:submit="apiCloudflareConfig">
                                <div class="grid gap-4 lg:grid-cols-2">
                                    <x-forms.listbox id="cloudflare_integration_token_id" label="Cloudflare token"
                                        required placeholder="Select a token"
                                        :options="$tunnelTokens->map(fn ($token) => ['value' => $token->id, 'label' => $token->name])->all()" />
                                    <x-forms.input id="api_ssh_domain" label="SSH hostname" required
                                        placeholder="ssh.example.com"
                                        helper="A hostname in a zone of the selected token. Cloudflare routes it to SSH port {{ $server->port }} of this server." />
                                </div>
                                <div class="mt-4 flex justify-end">
                                    <x-forms.button type="submit" isHighlighted>Set up tunnel</x-forms.button>
                                </div>
                            </form>
                            @if ($dnsConflict)
                                <div class="mt-4">
                                    <x-callout type="warning" title="A DNS record already exists">
                                        {{ $dnsConflict['hostname'] }} already points to {{ $dnsConflict['current'] }}.
                                        Replace it with the tunnel record to continue.
                                    </x-callout>
                                    <div class="mt-4">
                                        <x-modal-confirmation title="Replace DNS record?" buttonTitle="Replace DNS record"
                                            submitAction="replaceDnsAndConfigure" :actions="[
                                                'The DNS record of '.$dnsConflict['hostname'].' will point to the Cloudflare tunnel.',
                                                'Traffic that uses the current record value will stop.',
                                            ]" :confirmWithText="false" :confirmWithPassword="false" />
                                    </div>
                                </div>
                            @endif
                        @endif

                        <div class="mt-4 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                            <x-forms.collapsible title="Use an existing tunnel with its tunnel token">
                                <p class="text-sm leading-6 text-neutral-600 dark:text-fg-dim">
                                    For a tunnel you created in the Cloudflare dashboard. Coolify does not need a
                                    Cloudflare API token for this.
                                </p>
                                <form class="mt-4" @submit.prevent="$wire.dispatch('automatedCloudflareConfig')">
                                    <div class="grid gap-4 lg:grid-cols-2">
                                        <x-forms.input id="cloudflare_token" required label="Tunnel token" type="password"
                                            helper="The token of the tunnel connector, from the Cloudflare dashboard." />
                                        <x-forms.input id="ssh_domain" label="SSH domain" required
                                            helper="The public hostname of the tunnel that routes to SSH on this server, without a protocol." />
                                    </div>
                                    <div class="mt-4 flex justify-end">
                                        <x-forms.button type="submit">Configure tunnel</x-forms.button>
                                    </div>
                                </form>
                            </x-forms.collapsible>
                        </div>
                    @endcannot
                @endif
            </x-application.settings-section>

            @if (!$isCloudflareTunnelsEnabled && $server->isFunctional())
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
