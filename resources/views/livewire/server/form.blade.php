<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex gap-2">
            <h2>General</h2>
            @if ($server->id === 0)
                <x-modal-confirmation buttonTitle="Save" title="Change Localhost" action="submit">
                    You could lose a lot of functionalities if you change the server details of the server where Coolify
                    is
                    running on.<br>Please think again.
                </x-modal-confirmation>
            @else
                <x-forms.button type="submit">Save</x-forms.button>
                @if ($server->isFunctional())
                    <x-slide-over closeWithX fullScreen>
                        <x-slot:title>Validate & configure</x-slot:title>
                        <x-slot:content>
                            <livewire:server.validate-and-install :server="$server" ask />
                        </x-slot:content>
                        <x-forms.button @click="slideOverOpen=true" wire:click.prevent='validateServer' isHighlighted>
                            Revalidate server
                        </x-forms.button>
                    </x-slide-over>
                @endif
            @endif
        </div>
        @if ($server->isFunctional())
            Server is reachable and validated.
        @else
            You can't use this server until it is validated.
        @endif
        @if ((!$server->settings->is_reachable || !$server->settings->is_usable) && $server->id !== 0)
            <x-slide-over closeWithX fullScreen>
                <x-slot:title>Validate & configure</x-slot:title>
                <x-slot:content>
                    <livewire:server.validate-and-install :server="$server" />
                </x-slot:content>
                <x-forms.button @click="slideOverOpen=true"
                    class="w-full mt-8 mb-4 font-bold box-without-bg bg-coollabs hover:bg-coollabs-100"
                    wire:click.prevent='validateServer' isHighlighted>
                    Validate Server & Install Docker Engine
                </x-forms.button>
            </x-slide-over>
        @endif
        @if ((!$server->settings->is_reachable || !$server->settings->is_usable) && $server->id === 0)
            <x-forms.button class="mt-8 mb-4 font-bold box-without-bg bg-coollabs hover:bg-coollabs-100"
                wire:click.prevent='checkLocalhostConnection' isHighlighted>
                Validate Server
            </x-forms.button>
        @endif
        @if ($server->isForceDisabled() && isCloud())
            <div class="pt-4 font-bold text-red-500">The system has disabled the server because you have exceeded the
                number of servers for which you have paid.</div>
        @endif
        <div class="flex flex-col gap-2 pt-4">
            <div class="flex flex-col w-full gap-2 lg:flex-row">
                <x-forms.input id="server.name" label="Name" required />
                <x-forms.input id="server.description" label="Description" />
                @if (!$server->settings->is_swarm_worker && !$server->settings->is_build_server)
                    <x-forms.input placeholder="https://example.com" id="wildcard_domain" label="Wildcard Domain"
                        helper='A wildcard domain allows you to receive a randomly generated domain for your new applications. <br><br>For instance, if you set "https://example.com" as your wildcard domain, your applications will receive domains like "https://randomId.example.com".' />
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
                @if ($server->isFunctional())
                    @if (!$server->isLocalhost())
                        <x-forms.checkbox instantSave id="server.settings.is_build_server"
                            label="Use it as a build server?" />
                        <div class="flex items-center gap-1 pt-6">
                            <h3 class="">Cloudflare Tunnels
                            </h3>
                            <x-helper class="inline-flex"
                                helper="If you are using Cloudflare Tunnels, enable this. It will proxy all SSH requests to your server through Cloudflare.<br><span class='dark:text-warning'>Coolify does not install or set up Cloudflare (cloudflared) on your server.</span>" />
                        </div>
                        @if ($server->settings->is_cloudflare_tunnel)
                            <x-forms.checkbox instantSave id="server.settings.is_cloudflare_tunnel" label="Enabled" />
                        @else
                            <x-modal-input buttonTitle="Configure" title="Cloudflare Tunnels">
                                <livewire:server.configure-cloudflare-tunnels :server_id="$server->id" />
                            </x-modal-input>
                        @endif
                        @if (!$server->isBuildServer())
                            <h3 class="pt-6">Swarm <span class="text-xs text-neutral-500">(experimental)</span></h3>
                            <div class="pb-4">Read the docs <a class='underline dark:text-white'
                                    href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>.
                            </div>
                            @if ($server->settings->is_swarm_worker)
                                <x-forms.checkbox disabled instantSave type="checkbox"
                                    id="server.settings.is_swarm_manager"
                                    helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                                    label="Is it a Swarm Manager?" />
                            @else
                                <x-forms.checkbox instantSave type="checkbox" id="server.settings.is_swarm_manager"
                                    helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                                    label="Is it a Swarm Manager?" />
                            @endif

                            @if ($server->settings->is_swarm_manager)
                                <x-forms.checkbox disabled instantSave type="checkbox"
                                    id="server.settings.is_swarm_worker"
                                    helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                                    label="Is it a Swarm Worker?" />
                            @else
                                <x-forms.checkbox instantSave type="checkbox" id="server.settings.is_swarm_worker"
                                    helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                                    label="Is it a Swarm Worker?" />
                            @endif
                        @endif
                    @endif
                @else
                    <div class="flex items-center gap-1 pt-6">
                        <h3 class="">Cloudflare Tunnels
                        </h3>
                        <x-helper class="inline-flex"
                            helper="If you are using Cloudflare Tunnels, enable this. It will proxy all SSH requests to your server through Cloudflare.<br><span class='dark:text-warning'>Coolify does not install or set up Cloudflare (cloudflared) on your server.</span>" />
                    </div>
                    @if ($server->settings->is_cloudflare_tunnel)
                        <x-forms.checkbox instantSave id="server.settings.is_cloudflare_tunnel" label="Enabled" />
                    @else
                        <x-modal-input buttonTitle="Configure" title="Cloudflare Tunnels">
                            <livewire:server.configure-cloudflare-tunnels :server_id="$server->id" />
                        </x-modal-input>
                    @endif
                @endif

            </div>
        </div>

        @if ($server->isFunctional())
            <h3 class="pt-4">Settings</h3>
            <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                <x-forms.input id="cleanup_after_percentage" label="Disk cleanup threshold (%)" required
                    helper="The disk cleanup task will run when the disk usage exceeds this threshold." />
                <x-forms.input id="server.settings.concurrent_builds" label="Number of concurrent builds" required
                    helper="You can specify the number of simultaneous build processes/deployments that should run concurrently." />
                <x-forms.input id="server.settings.dynamic_timeout" label="Deployment timeout (seconds)" required
                    helper="You can define the maximum duration for a deployment to run before timing it out." />
            </div>
            <div class="flex items-center gap-2 pt-4 pb-2">
                <h3>Sentinel</h3>
                {{-- @if ($server->isSentinelEnabled()) --}}
                {{-- <x-forms.button wire:click='restartSentinel'>Restart</x-forms.button> --}}
                {{-- @endif --}}
            </div>
            <div>Metrics are disabled until a few bugs are fixed.</div>
            {{-- <div class="w-64">
                <x-forms.checkbox instantSave id="server.settings.is_metrics_enabled" label="Enable Metrics" />
            </div>
            <div class="pt-4">
                <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                    <x-forms.input type="password" id="server.settings.metrics_token" label="Metrics token" required
                        helper="Token for collector (Sentinel)." />
                    <x-forms.input id="server.settings.metrics_refresh_rate_seconds" label="Metrics rate (seconds)"
                        required
                        helper="The interval for gathering metrics. Lower means more disk space will be used." />
                    <x-forms.input id="server.settings.metrics_history_days" label="Metrics history (days)" required
                        helper="How many days should the metrics data should be reserved." />
                </div>
            </div>  --}}
        @endif
    </form>
</div>
