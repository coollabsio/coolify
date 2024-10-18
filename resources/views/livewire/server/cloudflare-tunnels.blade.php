<div>
    <div class="flex gap-1 items-center">
        <h2>Cloudflare Tunnels</h2>
        <x-helper class="inline-flex"
            helper="If you are using Cloudflare Tunnels, enable this. It will proxy all SSH requests to your server through Cloudflare.<br> You then can close your server's SSH port in the firewall of your hosting provider.<br><span class='dark:text-warning'>If you choose manual configuration, Coolify does not install or set up Cloudflare (cloudflared) on your server.</span>" />
    </div>
    <div class="flex flex-col gap-2 pt-6">
        @if ($server->settings->is_cloudflare_tunnel)
            <div class="w-64">
                <x-forms.checkbox instantSave id="server.settings.is_cloudflare_tunnel"
                    label="Enabled" />
            </div>
        @elseif (!$server->isFunctional())
            <div
                class="p-4 mb-4 w-full text-sm text-yellow-800 bg-yellow-100 rounded dark:bg-yellow-900 dark:text-yellow-300">
                To <span class="font-semibold">automatically</span> configure Cloudflare Tunnels, please
                validate your server first.</span> Then you will need a Cloudflare token and an SSH
                domain configured.
                <br />
                To <span class="font-semibold">manually</span> configure Cloudflare Tunnels, please
                click <span wire:click="manualCloudflareConfig"
                    class="underline cursor-pointer">here</span>, then you should validate the server.
                <br /><br />
                For more information, please read our <a
                    href="https://coolify.io/docs/knowledge-base/cloudflare/tunnels/" target="_blank"
                    class="font-medium underline hover:text-yellow-600 dark:hover:text-yellow-200">documentation</a>.
            </div>
        @endif
        @if (!$server->settings->is_cloudflare_tunnel && $server->isFunctional())
            <x-modal-input buttonTitle="Automated Configuration" title="Cloudflare Tunnels"
                class="w-full" :closeOutside="false">
                <livewire:server.configure-cloudflare-tunnels :server_id="$server->id" />
            </x-modal-input>
        @endif
        @if ($server->isFunctional() && !$server->settings->is_cloudflare_tunnel)
            <div wire:click="manualCloudflareConfig" class="w-full underline cursor-pointer">
                I have configured Cloudflare Tunnels manually
            </div>
        @endif

    </div>
</div>
