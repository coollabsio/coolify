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
            @if ($server->validation_logs)
                <h4>Previous Validation Logs</h4>
                <div class="pb-8">
                    {!! $server->validation_logs !!}
                </div>
            @endif
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
                <x-forms.input type="password" id="server.ip" label="IP Address/Domain"
                    helper="An IP Address (127.0.0.1) or domain (example.com)." required />
                <div class="flex gap-2">
                    <x-forms.input id="server.user" label="User" required />
                    <x-forms.input type="number" id="server.port" label="Port" required />
                </div>
            </div>
            <div class="w-full" x-data="{
                open: false,
                search: '{{ $server->settings->server_timezone ?: '' }}',
                timezones: @js($timezones),
                placeholder: '{{ $server->settings->server_timezone ? 'Search timezone...' : 'Select Server Timezone' }}',
                init() {
                    this.$watch('search', value => {
                        if (value === '') {
                            this.open = true;
                        }
                    })
                }
            }">
                <div class="flex items-center mb-1">
                    <label for="server.settings.server_timezone">Server
                        Timezone</label>
                    <x-helper class="ml-2" helper="Server's timezone. This is used for backups, cron jobs, etc." />
                </div>
                <div class="relative">
                    <div class="inline-flex items-center relative w-64">
                        <input wire:dirty.class.remove='dark:focus:ring-coolgray-300 dark:ring-coolgray-300'
                            wire:dirty.class="dark:focus:ring-warning dark:ring-warning" x-model="search"
                            @focus="open = true" @click.away="open = false" @input="open = true" class="w-full input "
                            :placeholder="placeholder" wire:model.debounce.300ms="server.settings.server_timezone">
                        <svg class="absolute right-0 w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none"
                            viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" @click="open = true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                        </svg>
                    </div>
                    <div x-show="open"
                        class="absolute z-50 w-64 mt-1 bg-white dark:bg-coolgray-100 border dark:border-coolgray-200 rounded-md shadow-lg max-h-60 overflow-auto scrollbar overflow-x-hidden">
                        <template
                            x-for="timezone in timezones.filter(tz => tz.toLowerCase().includes(search.toLowerCase()))"
                            :key="timezone">
                            <div @click="search = timezone; open = false; $wire.set('server.settings.server_timezone', timezone)"
                                class="px-4 py-2 cursor-pointer hover:bg-gray-100 dark:hover:bg-coolgray-300 text-gray-800 dark:text-gray-200"
                                x-text="timezone"></div>
                        </template>
                    </div>
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
            <div class="flex flex-col gap-1">
                <div class="flex flex-col gap-2">
                    <div class="flex flex-col flex-wrap gap-2 sm:flex-nowrap">
                        <div class="w-64">
                            <x-forms.checkbox
                                helper="Enable force Docker Cleanup. This will cleanup build caches / unused images / etc."
                                instantSave id="server.settings.force_docker_cleanup" label="Force Docker Cleanup" />
                        </div>
                        @if ($server->settings->force_docker_cleanup)
                            <x-forms.input placeholder="*/10 * * * *" id="server.settings.docker_cleanup_frequency"
                                label="Docker cleanup frequency" required
                                helper="Cron expression for Docker Cleanup.<br>You can use every_minute, hourly, daily, weekly, monthly, yearly.<br><br>Default is every 10 minutes." />
                        @else
                            <x-forms.input id="server.settings.docker_cleanup_threshold"
                                label="Docker cleanup threshold (%)" required
                                helper="The Docker cleanup tasks will run when the disk usage exceeds this threshold." />
                        @endif
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                    <x-forms.input id="server.settings.concurrent_builds" label="Number of concurrent builds" required
                        helper="You can specify the number of simultaneous build processes/deployments that should run concurrently." />
                    <x-forms.input id="server.settings.dynamic_timeout" label="Deployment timeout (seconds)" required
                        helper="You can define the maximum duration for a deployment to run before timing it out." />
                </div>
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
