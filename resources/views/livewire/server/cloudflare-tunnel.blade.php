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
                    <h2>Cloudflare Tunnel</h2>
                    <x-helper class="inline-flex"
                        helper="If you are using Cloudflare Tunnel, enable this. It will proxy all SSH requests to your server through Cloudflare.<br> You then can close your server's SSH port in the firewall of your hosting provider.<br><span class='dark:text-warning'>If you choose manual configuration, Coolify does not install or set up Cloudflare (cloudflared) on your server.</span>" />
                    @if ($isCloudflareTunnelsEnabled)
                        <span
                            class="px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded dark:text-green-100 dark:bg-green-800">
                            Enabled
                        </span>
                    @endif
                </div>
                <div>Secure your servers with Cloudflare Tunnel.</div>
            </div>
            <div class="flex flex-col gap-2 pt-6">
                @if ($isCloudflareTunnelsEnabled)
                    <div class="flex flex-col gap-4">
                        <div
                            class="w-full px-4 py-2 text-yellow-800 rounded-xs border-l-4 border-yellow-500 bg-yellow-50 dark:bg-yellow-900/30 dark:text-yellow-300 dark:border-yellow-600">
                            <p class="font-bold">Warning!</p>
                            <p>If you disable Cloudflare Tunnel, you will need to update the server's IP address back
                                to
                                its real IP address in the server "General" settings. The server may become inaccessible
                                if the IP
                                address is not updated correctly.</p>
                        </div>
                        <div class="w-64">
                            @if ($server->ip_previous)
                                <x-modal-confirmation title="Disable Cloudflare Tunnel?"
                                    buttonTitle="Disable Cloudflare Tunnel" isErrorButton
                                    submitAction="toggleCloudflareTunnels" :actions="[
                                        'Cloudflare Tunnel will be disabled for this server.',
                                        'The server IP address will be updated to its previous IP address.',
                                    ]"
                                    confirmationText="DISABLE CLOUDFLARE TUNNEL"
                                    confirmationLabel="Please type the confirmation text to disable Cloudflare Tunnel."
                                    shortConfirmationLabel="Confirmation text"
                                    step3ButtonText="Disable Cloudflare Tunnel" />
                            @else
                                <x-modal-confirmation title="Disable Cloudflare Tunnel?"
                                    buttonTitle="Disable Cloudflare Tunnel" isErrorButton
                                    submitAction="toggleCloudflareTunnels" :actions="[
                                        'Cloudflare Tunnel will be disabled for this server.',
                                        'You will need to update the server IP address to its real IP address.',
                                        'The server may become inaccessible if the IP address is not updated correctly.',
                                        'SSH access will revert to the standard port configuration.',
                                    ]"
                                    confirmationText="DISABLE CLOUDFLARE TUNNEL"
                                    confirmationLabel="Please type the confirmation text to disable Cloudflare Tunnel."
                                    shortConfirmationLabel="Confirmation text"
                                    step3ButtonText="Disable Cloudflare Tunnel" />
                            @endif

                        </div>
                    </div>
                @elseif (!$server->isFunctional())
                    <div
                        class="p-4 mb-4 w-full text-sm text-yellow-800 bg-yellow-100 rounded-sm dark:bg-yellow-900 dark:text-yellow-300">
                        To <span class="font-semibold">automatically</span> configure Cloudflare Tunnel, please
                        validate your server first.</span> Then you will need a Cloudflare token and an SSH
                        domain configured.
                        <br />
                        To <span class="font-semibold">manually</span> configure Cloudflare Tunnel, please
                        click <span wire:click="manualCloudflareConfig" class="underline cursor-pointer">here</span>,
                        then you should validate the server.
                        <br /><br />
                        For more information, please read our <a
                            href="https://coolify.io/docs/knowledge-base/cloudflare/tunnels/server-ssh" target="_blank"
                            class="underline ">documentation</a>.
                    </div>
                @endif
                @if (!$isCloudflareTunnelsEnabled && $server->isFunctional())
                    <div class="flex  flex-col pb-2">
                        <h3>Automated </h3>
                        <a href="https://coolify.io/docs/knowledge-base/cloudflare/tunnels/server-ssh" target="_blank"
                            class="text-xs underline hover:text-yellow-600 dark:hover:text-yellow-200">Docs<x-external-link /></a>
                    </div>
                    <div class="flex gap-2">
                        <x-slide-over @automated.window="slideOverOpen = true" fullScreen>
                            <x-slot:title>Cloudflare Tunnel Configuration</x-slot:title>
                            <x-slot:content>
                                <livewire:activity-monitor header="Logs" fullHeight />
                            </x-slot:content>
                        </x-slide-over>
                        <form @submit.prevent="$wire.dispatch('automatedCloudflareConfig')"
                            class="flex flex-col gap-2 w-full">
                            <x-forms.input id="cloudflare_token" required label="Cloudflare Token" type="password" />
                            <x-forms.input id="ssh_domain" label="Configured SSH Domain" required
                                helper="The SSH domain you configured in Cloudflare. Make sure there is no protocol like http(s):// so you provide a FQDN not a URL. <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/cloudflare/tunnels/server-ssh' target='_blank'>Documentation</a>" />
                            <x-forms.button type="submit" isHighlighted>Continue</x-forms.button>
                        </form>
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
            <h3 class="pt-6 pb-2">Manual</h3>
            <div class="pl-2">
                <x-modal-confirmation buttonFullWidth title="I manually configured Cloudflare Tunnel?"
                    buttonTitle="I manually configured Cloudflare Tunnel" submitAction="manualCloudflareConfig"
                    :actions="[
                        'You set everything up manually, including in Cloudflare and on the server (cloudflared is running).',
                        'If you missed something, the connection will not work.',
                    ]" confirmationText="I manually configured Cloudflare Tunnel"
                    confirmationLabel="Please type the confirmation text to confirm that you manually configured Cloudflare Tunnel."
                    shortConfirmationLabel="Confirmation text" step3ButtonText="Confirm" />
            </div>
            @endif
        </div>
    </div>
</div>
