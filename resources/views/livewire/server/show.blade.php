<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > General | Coolify
    </x-slot>
    <x-server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="general" />
        <div class="w-full">
            <form wire:submit.prevent='submit' class="flex flex-col">
                <div class="flex gap-2">
                    <h2>General</h2>
                    @if ($server->id === 0)
                        <x-modal-confirmation title="Confirm Server Settings Change?" buttonTitle="Save"
                            submitAction="submit" :actions="[
                                'If you missconfigure the server, you could lose a lot of functionalities of Coolify.',
                            ]" :confirmWithText="false" :confirmWithPassword="false"
                            step2ButtonText="Save" />
                    @else
                        <x-forms.button type="submit">Save</x-forms.button>
                        @if ($server->isFunctional())
                            <x-slide-over closeWithX fullScreen>
                                <x-slot:title>Validate & configure</x-slot:title>
                                <x-slot:content>
                                    <livewire:server.validate-and-install :server="$server" ask />
                                </x-slot:content>
                                <x-forms.button @click="slideOverOpen=true" wire:click.prevent='validateServer'
                                    isHighlighted>
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
                @if ((!$isReachable || !$isUsable) && $server->id !== 0)
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
                @if ((!$isReachable || !$isUsable) && $server->id === 0)
                    <x-forms.button class="mt-8 mb-4 font-bold box-without-bg bg-coollabs hover:bg-coollabs-100"
                        wire:click.prevent='checkLocalhostConnection' isHighlighted>
                        Validate Server
                    </x-forms.button>
                @endif
                @if ($server->isForceDisabled() && isCloud())
                    <div class="pt-4 font-bold text-red-500">The system has disabled the server because you have
                        exceeded the
                        number of servers for which you have paid.</div>
                @endif
                <div class="flex flex-col gap-2 pt-4">
                    <div class="flex flex-col gap-2 w-full lg:flex-row">
                        <x-forms.input id="name" label="Name" required />
                        <x-forms.input id="description" label="Description" />
                        @if (!$isSwarmWorker && !$isBuildServer)
                            <x-forms.input placeholder="https://example.com" id="wildcardDomain" label="Wildcard Domain"
                                helper='A wildcard domain allows you to receive a randomly generated domain for your new applications. <br><br>For instance, if you set "https://example.com" as your wildcard domain, your applications will receive domains like "https://randomId.example.com".' />
                        @endif

                    </div>
                    <div class="flex flex-col gap-2 w-full lg:flex-row">
                        <x-forms.input type="password" id="ip" label="IP Address/Domain"
                            helper="An IP Address (127.0.0.1) or domain (example.com). Make sure there is no protocol like http(s):// so you provide a FQDN not a URL."
                            required />
                        <div class="flex gap-2">
                            <x-forms.input id="user" label="User" required />
                            <x-forms.input type="number" id="port" label="Port" required />
                        </div>
                    </div>
                    <div class="w-full" x-data="{
                        open: false,
                        search: '{{ $serverTimezone ?: '' }}',
                        timezones: @js($this->timezones),
                        placeholder: '{{ $serverTimezone ? 'Search timezone...' : 'Select Server Timezone' }}',
                        init() {
                            this.$watch('search', value => {
                                if (value === '') {
                                    this.open = true;
                                }
                            })
                        }
                    }">
                        <div class="flex items-center mb-1">
                            <label for="serverTimezone">Server
                                Timezone</label>
                            <x-helper class="ml-2"
                                helper="Server's timezone. This is used for backups, cron jobs, etc." />
                        </div>
                        <div class="relative">
                            <div class="inline-flex relative items-center w-64">
                                <input autocomplete="off"
                                    wire:dirty.class.remove='dark:focus:ring-coolgray-300 dark:ring-coolgray-300'
                                    wire:dirty.class="dark:focus:ring-warning dark:ring-warning" x-model="search"
                                    @focus="open = true" @click.away="open = false" @input="open = true"
                                    class="w-full input" :placeholder="placeholder" wire:model="serverTimezone">
                                <svg class="absolute right-0 mr-2 w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                                    fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                                    @click="open = true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                                </svg>
                            </div>
                            <div x-show="open"
                                class="overflow-auto overflow-x-hidden absolute z-50 mt-1 w-64 max-h-60 bg-white rounded-md border shadow-lg dark:bg-coolgray-100 dark:border-coolgray-200 scrollbar">
                                <template
                                    x-for="timezone in timezones.filter(tz => tz.toLowerCase().includes(search.toLowerCase()))"
                                    :key="timezone">
                                    <div @click="search = timezone; open = false; $wire.set('serverTimezone', timezone); $wire.submit()"
                                        class="px-4 py-2 text-gray-800 cursor-pointer hover:bg-gray-100 dark:hover:bg-coolgray-300 dark:text-gray-200"
                                        x-text="timezone"></div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="w-full">
                        @if (!$server->isLocalhost())
                            <div class="w-96">
                                <x-forms.checkbox instantSave id="isBuildServer" label="Use it as a build server?" />
                            </div>

                            @if (!$server->isBuildServer() && !$server->settings->is_cloudflare_tunnel)
                                <h3 class="pt-6">Swarm <span class="text-xs text-neutral-500">(experimental)</span>
                                </h3>
                                <div class="pb-4">Read the docs <a class='underline dark:text-white'
                                        href='https://coolify.io/docs/knowledge-base/docker/swarm'
                                        target='_blank'>here</a>.
                                </div>
                                <div class="w-96">
                                    @if ($server->settings->is_swarm_worker)
                                        <x-forms.checkbox disabled instantSave type="checkbox" id="isSwarmManager"
                                            helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                                            label="Is it a Swarm Manager?" />
                                    @else
                                        <x-forms.checkbox instantSave type="checkbox" id="isSwarmManager"
                                            helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                                            label="Is it a Swarm Manager?" />
                                    @endif

                                    @if ($server->settings->is_swarm_manager)
                                        <x-forms.checkbox disabled instantSave type="checkbox" id="isSwarmWorker"
                                            helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                                            label="Is it a Swarm Worker?" />
                                    @else
                                        <x-forms.checkbox instantSave type="checkbox" id="isSwarmWorker"
                                            helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                                            label="Is it a Swarm Worker?" />
                                    @endif
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </form>
            @if ($server->isFunctional() && !$server->isSwarm() && !$server->isBuildServer())
                <form wire:submit.prevent='submit'>
                    <div class="flex gap-2 items-center pt-4 pb-2">
                        <h3>Sentinel</h3>
                        @if ($server->isSentinelEnabled())
                            <div class="flex gap-2 items-center">
                                @if ($server->isSentinelLive())
                                    <x-status.running status="In sync" noLoading title="{{ $sentinelUpdatedAt }}" />
                                    <x-forms.button type="submit">Save</x-forms.button>
                                    <x-forms.button wire:click='restartSentinel'>Restart</x-forms.button>
                                @else
                                    <x-status.stopped status="Out of sync" noLoading
                                        title="{{ $sentinelUpdatedAt }}" />
                                    <x-forms.button type="submit">Save</x-forms.button>
                                    <x-forms.button wire:click='restartSentinel'>Sync</x-forms.button>
                                @endif
                            </div>
                        @endif
                    </div>
                    <div class="flex flex-col gap-2">
                        <div class="flex gap-2">Experimental feature <x-helper
                                helper="Sentinel reports your server's & container's health and collects metrics." />
                        </div>
                        <div class="w-64">
                            <x-forms.checkbox wire:model.live="isSentinelEnabled" label="Enable Sentinel" />
                            @if ($server->isSentinelEnabled())
                                <x-forms.checkbox id="isSentinelDebugEnabled" label="Enable Sentinel Debug"
                                    instantSave />
                                <x-forms.checkbox instantSave id="isMetricsEnabled" label="Enable Metrics" />
                            @else
                                <x-forms.checkbox id="isSentinelDebugEnabled" label="Enable Sentinel Debug" disabled
                                    instantSave />
                                <x-forms.checkbox instantSave disabled id="isMetricsEnabled" label="Enable Metrics" />
                            @endif
                        </div>
                        @if ($server->isSentinelEnabled())
                            <div class="flex flex-wrap gap-2 sm:flex-nowrap items-end">
                                <x-forms.input type="password" id="sentinelToken" label="Sentinel token" required
                                    helper="Token for Sentinel." />
                                <x-forms.button wire:click="regenerateSentinelToken">Regenerate</x-forms.button>
                            </div>

                            <x-forms.input id="sentinelCustomUrl" required label="Coolify URL"
                                helper="URL to your Coolify instance. If it is empty that means you do not have a FQDN set for your Coolify instance." />

                            <div class="flex flex-col gap-2">
                                <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                                    <x-forms.input id="sentinelMetricsRefreshRateSeconds"
                                        label="Metrics rate (seconds)" required
                                        helper="Interval used for gathering metrics. Lower values result in more disk space usage." />
                                    <x-forms.input id="sentinelMetricsHistoryDays" label="Metrics history (days)"
                                        required helper="Number of days to retain metrics data for." />
                                    <x-forms.input id="sentinelPushIntervalSeconds" label="Push interval (seconds)"
                                        required helper="Interval at which metrics data is sent to the collector." />
                                </div>
                            </div>
                        @endif
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>
