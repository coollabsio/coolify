<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex gap-2">
            <h2>General</h2>
            @if ($server->id === 0)
                <x-new-modal buttonTitle="Save" title="Change Localhost" action="submit">
                    You could lost a lot of functionalities if you change the server details of the server where Coolify
                    is
                    running on.<br>Please think again.
                </x-new-modal>
            @else
                <x-forms.button type="submit">Save</x-forms.button>
            @endif
        </div>
        @if (!$server->isFunctional())
            You can't use this server until it is validated.
        @else
            Server is reachable and validated.
        @endif
        @if ((!$server->settings->is_reachable || !$server->settings->is_usable) && $server->id !== 0)
            <x-forms.button class="mt-8 mb-4 font-bold box-without-bg bg-coollabs hover:bg-coollabs-100"
                wire:click.prevent='validateServer' isHighlighted>
                Validate Server & Install Docker Engine
            </x-forms.button>
        @endif
        @if ((!$server->settings->is_reachable || !$server->settings->is_usable) && $server->id === 0)
            <x-forms.button class="mt-8 mb-4 font-bold box-without-bg bg-coollabs hover:bg-coollabs-100"
                wire:click.prevent='checkLocalhostConnection' isHighlighted>
                Validate Server
            </x-forms.button>
        @endif
        <div class="flex flex-col gap-2 pt-4">
            <div class="flex flex-col w-full gap-2 lg:flex-row">
                <x-forms.input id="server.name" label="Name" required />
                <x-forms.input id="server.description" label="Description" />
                @if (!$server->settings->is_swarm_worker && !$server->settings->is_build_server)
                    <x-forms.input placeholder="https://example.com" id="wildcard_domain" label="Wildcard Domain"
                        helper="Wildcard domain for your applications. If you set this, you will get a random generated domain for your new applications.<br><span class='font-bold text-white'>Example:</span><br>In case you set:<span class='text-helper'>https://example.com</span> your applications will get:<br> <span class='text-helper'>https://randomId.example.com</span>" />
                @endif

            </div>
            <div class="flex flex-col w-full gap-2 lg:flex-row">
                <x-forms.input id="server.ip" label="IP Address/Domain"
                    helper="An IP Address (127.0.0.1) or domain (example.com)." required />
                <div class="flex gap-2">
                    <x-forms.input id="server.user" label="User" required />
                    <x-forms.input type="number" id="server.port" label="Port" required />
                </div>
            </div>
            <div class="w-64">
                @if (!$server->isLocalhost())
                    @if ($server->settings->is_build_server)
                        <x-forms.checkbox instantSave disabled id="server.settings.is_build_server"
                            label="Use it as a build server?" />
                    @else
                        <x-forms.checkbox instantSave
                            helper="If you are using Cloudflare Tunnels, enable this. It will proxy all ssh requests to your server through Cloudflare.<br><span class='text-warning'>Coolify does not install/setup Cloudflare (cloudflared) on your server.</span>"
                            id="server.settings.is_cloudflare_tunnel" label="Cloudflare Tunnel" />
                        @if ($server->isSwarm())
                            <div class="pt-6"> Swarm support is experimental. </div>
                        @endif
                        @if ($server->settings->is_swarm_worker)
                            <x-forms.checkbox disabled instantSave type="checkbox" id="server.settings.is_swarm_manager"
                                helper="For more information, please read the documentation <a class='text-white' href='https://coolify.io/docs/docker/swarm' target='_blank'>here</a>."
                                label="Is it a Swarm Manager?" />
                        @else
                            <x-forms.checkbox instantSave type="checkbox" id="server.settings.is_swarm_manager"
                                helper="For more information, please read the documentation <a class='text-white' href='https://coolify.io/docs/docker/swarm' target='_blank'>here</a>."
                                label="Is it a Swarm Manager?" />
                        @endif
                        @if ($server->settings->is_swarm_manager)
                            <x-forms.checkbox disabled instantSave type="checkbox" id="server.settings.is_swarm_worker"
                                helper="For more information, please read the documentation <a class='text-white' href='https://coolify.io/docs/docker/swarm' target='_blank'>here</a>."
                                label="Is it a Swarm Worker?" />
                        @else
                            <x-forms.checkbox instantSave type="checkbox" id="server.settings.is_swarm_worker"
                                helper="For more information, please read the documentation <a class='text-white' href='https://coolify.io/docs/docker/swarm' target='_blank'>here</a>."
                                label="Is it a Swarm Worker?" />
                        @endif
                    @endif
                @endif
            </div>
        </div>

        @if ($server->isFunctional())
            <h3 class="py-4">Settings</h3>
            <div class="flex gap-2">
                <x-forms.input id="cleanup_after_percentage" label="Disk cleanup threshold (%)" required
                    helper="Disk cleanup job will be executed if disk usage is more than this number." />
                <x-forms.input id="server.settings.concurrent_builds" label="Number of concurrent builds" required
                    helper="You can define how many concurrent builds processes / deployments should run at the same time." />
            </div>
        @endif
    </form>
</div>
