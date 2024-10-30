<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex gap-2">
            <h2>General</h2>
            @if ($server->id === 0)
                <x-modal-confirmation title="Confirm Server Settings Change?" buttonTitle="Save" submitAction="submit"
                    :actions="[
                        'You could lose a lot of functionalities if you change the server details of the server where Coolify is running on.',
                    ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Save" />
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
                    class="mt-8 mb-4 w-full font-bold box-without-bg bg-coollabs hover:bg-coollabs-100"
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
            <div class="flex flex-col gap-2 w-full lg:flex-row">
                <x-forms.input id="server.name" label="Name" required />
                <x-forms.input id="server.description" label="Description" />
                @if (!$server->settings->is_swarm_worker && !$server->settings->is_build_server)
                    <x-forms.input placeholder="https://example.com" id="wildcard_domain" label="Wildcard Domain"
                        helper='A wildcard domain allows you to receive a randomly generated domain for your new applications. <br><br>For instance, if you set "https://example.com" as your wildcard domain, your applications will receive domains like "https://randomId.example.com".' />
                @endif

            </div>
            <div class="flex flex-col gap-2 w-full lg:flex-row">
                <x-forms.input type="password" id="server.ip" label="IP Address/Domain"
                    helper="An IP Address (127.0.0.1) or domain (example.com). Make sure there is no protocol like http(s):// so you provide a FQDN not a URL." required />
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
                    <div class="inline-flex relative items-center w-64">
                        <input autocomplete="off" wire:dirty.class.remove='dark:focus:ring-coolgray-300 dark:ring-coolgray-300'
                            wire:dirty.class="dark:focus:ring-warning dark:ring-warning" x-model="search"
                            @focus="open = true" @click.away="open = false" @input="open = true" class="w-full input"
                            :placeholder="placeholder" wire:model.debounce.300ms="server.settings.server_timezone">
                        <svg class="absolute right-0 mr-2 w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none"
                            viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" @click="open = true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                        </svg>
                    </div>
                    <div x-show="open"
                        class="overflow-auto overflow-x-hidden absolute z-50 mt-1 w-64 max-h-60 bg-white rounded-md border shadow-lg dark:bg-coolgray-100 dark:border-coolgray-200 scrollbar">
                        <template
                            x-for="timezone in timezones.filter(tz => tz.toLowerCase().includes(search.toLowerCase()))"
                            :key="timezone">
                            <div @click="search = timezone; open = false; $wire.set('server.settings.server_timezone', timezone)"
                                class="px-4 py-2 text-gray-800 cursor-pointer hover:bg-gray-100 dark:hover:bg-coolgray-300 dark:text-gray-200"
                                x-text="timezone"></div>
                        </template>
                    </div>
                </div>
            </div>

            <div class="{{ $server->isFunctional() ? 'w-96' : 'w-full' }}">
                @if (!$server->isLocalhost())
                    <x-forms.checkbox instantSave id="server.settings.is_build_server"
                        label="Use it as a build server?" />
                    <div class="flex flex-col gap-2 pt-6">
                        <div class="flex gap-1 items-center">
                            <h3 class="text-lg font-semibold">Cloudflare Tunnels</h3>
                            <x-helper class="inline-flex"
                                helper="If you are using Cloudflare Tunnels, enable this. It will proxy all SSH requests to your server through Cloudflare.<br> You then can close your server's SSH port in the firewall of your hosting provider.<br><span class='dark:text-warning'>If you choose manual configuration, Coolify does not install or set up Cloudflare (cloudflared) on your server.</span>" />
                        </div>
                        @if ($server->settings->is_cloudflare_tunnel)
                            <div class="w-64">
                                <x-forms.checkbox instantSave id="server.settings.is_cloudflare_tunnel" label="Enabled" />
                            </div>
                        @elseif (!$server->isFunctional())
                            <div class="p-4 mb-4 w-full text-sm text-yellow-800 bg-yellow-100 rounded dark:bg-yellow-900 dark:text-yellow-300">
                                To <span class="font-semibold">automatically</span> configure Cloudflare Tunnels, please validate your server first.</span> Then you will need a Cloudflare token and an SSH domain configured.
                                <br/>
                                To <span class="font-semibold">manually</span> configure Cloudflare Tunnels, please click <span wire:click="manualCloudflareConfig" class="underline cursor-pointer">here</span>, then you should validate the server.
                                <br/><br/>
                                For more information, please read our <a href="https://coolify.io/docs/knowledge-base/cloudflare/tunnels/" target="_blank" class="font-medium underline hover:text-yellow-600 dark:hover:text-yellow-200">documentation</a>.
                            </div>
                        @endif
                        @if (!$server->settings->is_cloudflare_tunnel && $server->isFunctional())
                            <x-modal-input buttonTitle="Automated Configuration" title="Cloudflare Tunnels" class="w-full" :closeOutside="false">
                                <livewire:server.configure-cloudflare-tunnels :server_id="$server->id" />
                            </x-modal-input>
                        @endif
                        @if ($server->isFunctional() &&!$server->settings->is_cloudflare_tunnel)
                            <div wire:click="manualCloudflareConfig" class="w-full underline cursor-pointer">
                                I have configured Cloudflare Tunnels manually
                            </div>
                        @endif

                    </div>
                    @if (!$server->isBuildServer() && !$server->settings->is_cloudflare_tunnel)
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
            </div>
        </div>

        @if ($server->isFunctional())
            <h3 class="pt-4">Settings</h3>
            <div class="flex flex-col gap-4">
                <div class="flex flex-col gap-2">
                    <div class="flex flex-wrap items-center gap-4">
                        <div class="w-64">
                            <x-forms.checkbox
                                helper="Enabling Force Docker Cleanup or manually triggering a cleanup will perform the following actions:
                                <ul class='list-disc pl-4 mt-2'>
                                    <li>Removes stopped containers managed by Coolify (as containers are none persistent, no data will be lost).</li>
                                    <li>Deletes unused images.</li>
                                    <li>Clears build cache.</li>
                                    <li>Removes old versions of the Coolify helper image.</li>
                                    <li>Optionally delete unused volumes (if enabled in advanced options).</li>
                                    <li>Optionally remove unused networks (if enabled in advanced options).</li>
                                </ul>"
                                instantSave id="server.settings.force_docker_cleanup" label="Force Docker Cleanup" />
                        </div>
                        <x-modal-confirmation
                            title="Confirm Docker Cleanup?"
                            buttonTitle="Trigger Docker Cleanup"
                            submitAction="manualCleanup"
                            :actions="[
                                'Permanently deletes all stopped containers managed by Coolify (as containers are non-persistent, no data will be lost)',
                                'Permanently deletes all unused images',
                                'Clears build cache',
                                'Removes old versions of the Coolify helper image',
                                'Optionally permanently deletes all unused volumes (if enabled in advanced options).',
                                'Optionally permanently deletes all unused networks (if enabled in advanced options).'
                            ]"
                            :confirmWithText="false"
                            :confirmWithPassword="false"
                            step2ButtonText="Trigger Docker Cleanup"
                        />
                    </div>
                    @if ($server->settings->force_docker_cleanup)
                    <x-forms.input placeholder="*/10 * * * *" id="server.settings.docker_cleanup_frequency"
                        label="Docker cleanup frequency" required
                            helper="Cron expression for Docker Cleanup.<br>You can use every_minute, hourly, daily, weekly, monthly, yearly.<br><br>Default is every night at midnight." />
                    @else
                            <x-forms.input id="server.settings.docker_cleanup_threshold"
                                label="Docker cleanup threshold (%)" required
                                helper="The Docker cleanup tasks will run when the disk usage exceeds this threshold." />
                    @endif
                    <div x-data="{ open: false }" class="mt-4 max-w-md">
                        <button @click="open = !open" type="button" class="flex items-center justify-between w-full text-left text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100">
                            <span>Advanced Options</span>
                            <svg :class="{'rotate-180': open}" class="w-5 h-5 transition-transform duration-200" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                        <div x-show="open" class="mt-2 space-y-2">
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-2"><strong>Warning: Enable these options only if you fully understand their implications and consequences!</strong><br>Improper use will result in data loss and could cause functional issues.</p>
                            <x-forms.checkbox instantSave id="server.settings.delete_unused_volumes" label="Delete Unused Volumes"
                                helper="This option will remove all unused Docker volumes during cleanup.<br><br><strong>Warning: Data form stopped containers will be lost!</strong><br><br>Consequences include:<br>
                                <ul class='list-disc pl-4 mt-2'>
                                    <li>Volumes not attached to running containers will be deleted and data will be permanently lost (stopped containers are affected).</li>
                                    <li>Data from stopped containers volumes will be permanently lost.</li>
                                    <li>No way to recover deleted volume data.</li>
                                </ul>"
                            />
                            <x-forms.checkbox instantSave id="server.settings.delete_unused_networks" label="Delete Unused Networks"
                                helper="This option will remove all unused Docker networks during cleanup.<br><br><strong>Warning: Functionality may be lost and containers may not be able to communicate with each other!</strong><br><br>Consequences include:<br>
                                <ul class='list-disc pl-4 mt-2'>
                                    <li>Networks not attached to running containers will be permanently deleted (stopped containers are affected).</li>
                                    <li>Custom networks for stopped containers will be permanently deleted.</li>
                                    <li>Functionality may be lost and containers may not be able to communicate with each other.</li>
                                </ul>"
                            />
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-4 sm:flex-nowrap">
                    <x-forms.input id="server.settings.concurrent_builds" label="Number of concurrent builds" required
                        helper="You can specify the number of simultaneous build processes/deployments that should run concurrently." />
                    <x-forms.input id="server.settings.dynamic_timeout" label="Deployment timeout (seconds)" required
                        helper="You can define the maximum duration for a deployment to run before timing it out." />
                </div>
            </div>
            <div class="flex gap-2 items-center pt-4 pb-2">
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
