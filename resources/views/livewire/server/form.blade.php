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
                    helper="An IP Address (127.0.0.1) or domain (example.com). Make sure there is no protocol like http(s):// so you provide a FQDN not a URL."
                    required />
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
                        <input autocomplete="off"
                            wire:dirty.class.remove='dark:focus:ring-coolgray-300 dark:ring-coolgray-300'
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

            <div class="w-full">
                @if (!$server->isLocalhost())
                    <div class="w-96">
                        <x-forms.checkbox instantSave id="server.settings.is_build_server"
                            label="Use it as a build server?" />
                    </div>

                    @if (!$server->isBuildServer() && !$server->settings->is_cloudflare_tunnel)
                        <h3 class="pt-6">Swarm <span class="text-xs text-neutral-500">(experimental)</span></h3>
                        <div class="pb-4">Read the docs <a class='underline dark:text-white'
                                href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>.
                        </div>
                        <div class="w-96">
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
                        </div>
                    @endif
                @endif
            </div>
        </div>
        @if (!$server->isSwarm() && !$server->isBuildServer())
            <div class="flex gap-2 items-center pt-4 pb-2">
                <h3>Sentinel</h3>
                @if ($server->isSentinelEnabled())
                    <div class="flex gap-2 items-center"
                        wire:poll.{{ $server->settings->sentinel_push_interval_seconds }}s="checkSyncStatus">
                        @if ($server->isSentinelLive())
                            <x-status.running status="In sync" noLoading title="{{ $server->sentinel_updated_at }}" />
                            <x-forms.button wire:click='restartSentinel'>Restart</x-forms.button>
                        @else
                            <x-status.stopped status="Out of sync" noLoading
                                title="{{ $server->sentinel_updated_at }}" />
                            <x-forms.button wire:click='restartSentinel'>Sync</x-forms.button>
                        @endif
                    </div>
                @endif
            </div>
            <div class="flex flex-col gap-2">
                <div class="w-64">
                    <x-forms.checkbox wire:model.live="server.settings.is_sentinel_enabled" label="Enable Sentinel" />
                    @if ($server->isSentinelEnabled())
                        <x-forms.checkbox instantSave id="server.settings.is_metrics_enabled"
                            label="Enable Metrics" />
                    @else
                        <x-forms.checkbox instantSave disabled id="server.settings.is_metrics_enabled"
                            label="Enable Metrics" />
                    @endif
                </div>
                @if ($server->isSentinelEnabled())
                    <div class="flex flex-wrap gap-2 sm:flex-nowrap items-end">
                        <x-forms.input type="password" id="server.settings.sentinel_token" label="Sentinel token"
                            required helper="Token for Sentinel." />
                        <x-forms.button wire:click="regenerateSentinelToken">Regenerate</x-forms.button>
                    </div>

                    <x-forms.input id="server.settings.sentinel_custom_url" required label="Coolify URL"
                        helper="URL to your Coolify instance. If it is empty that means you do not have a FQDN set for your Coolify instance." />

                    <div class="flex flex-col gap-2">
                        <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                            <x-forms.input id="server.settings.sentinel_metrics_refresh_rate_seconds"
                                label="Metrics rate (seconds)" required
                                helper="The interval for gathering metrics. Lower means more disk space will be used." />
                            <x-forms.input id="server.settings.sentinel_metrics_history_days"
                                label="Metrics history (days)" required
                                helper="How many days should the metrics data should be reserved." />
                            <x-forms.input id="server.settings.sentinel_push_interval_seconds"
                                label="Push interval (seconds)" required
                                helper="How many seconds should the metrics data should be pushed to the collector." />
                        </div>
                    </div>
                @endif
            </div>
        @endif

    </form>
</div>
