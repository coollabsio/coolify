<div x-data x-init="@if ($server->hetzner_server_id && $server->cloudProviderToken && !$hetznerServerStatus) $wire.checkHetznerServerStatus() @endif">
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > General | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="general" />
        <div class="w-full">
            <form wire:submit.prevent='submit' class="flex flex-col">
                <div class="flex gap-2">
                    <h2>General</h2>
                    @if ($server->hetzner_server_id)
                        <div class="flex items-center">
                            <div @class([
                                'flex items-center gap-1.5 px-2 py-1 text-xs font-semibold rounded transition-all',
                                'bg-white dark:bg-coolgray-100 dark:text-white',
                            ])
                                @if (in_array($hetznerServerStatus, ['starting', 'initializing'])) wire:poll.5s="checkHetznerServerStatus" @endif>
                                <svg class="w-4 h-4" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                                    <rect width="200" height="200" fill="#D50C2D" rx="8" />
                                    <path d="M40 40 H60 V90 H140 V40 H160 V160 H140 V110 H60 V160 H40 Z"
                                        fill="white" />
                                </svg>
                                @if ($hetznerServerStatus)
                                    <span class="pl-1.5">
                                        @if (in_array($hetznerServerStatus, ['starting', 'initializing']))
                                            <svg class="inline animate-spin h-3 w-3 mr-1 text-coollabs dark:text-yellow-500"
                                                xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10"
                                                    stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor"
                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                </path>
                                            </svg>
                                        @endif
                                        <span @class([
                                            'text-green-500' => $hetznerServerStatus === 'running',
                                            'text-red-500' => $hetznerServerStatus === 'off',
                                        ])>
                                            {{ ucfirst($hetznerServerStatus) }}
                                        </span>
                                    </span>
                                @else
                                    <span class="pl-1.5">
                                        <svg class="inline animate-spin h-3 w-3 mr-1 text-coollabs dark:text-yellow-500"
                                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10"
                                                stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                            </path>
                                        </svg>
                                        <span>Checking status...</span>
                                    </span>
                                @endif
                            </div>
                            <button wire:loading.remove wire:target="checkHetznerServerStatus" title="Refresh Status"
                                wire:click.prevent='checkHetznerServerStatus(true)'
                                class="mx-1 dark:hover:fill-white fill-black dark:fill-warning">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M12 2a10.016 10.016 0 0 0-7 2.877V3a1 1 0 1 0-2 0v4.5a1 1 0 0 0 1 1h4.5a1 1 0 0 0 0-2H6.218A7.98 7.98 0 0 1 20 12a1 1 0 0 0 2 0A10.012 10.012 0 0 0 12 2zm7.989 13.5h-4.5a1 1 0 0 0 0 2h2.293A7.98 7.98 0 0 1 4 12a1 1 0 0 0-2 0a9.986 9.986 0 0 0 16.989 7.133V21a1 1 0 0 0 2 0v-4.5a1 1 0 0 0-1-1z" />
                                </svg>
                            </button>
                            <button wire:loading wire:target="checkHetznerServerStatus" title="Refreshing Status"
                                class="mx-1 dark:hover:fill-white fill-black dark:fill-warning">
                                <svg class="w-4 h-4 animate-spin" viewBox="0 0 24 24"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M12 2a10.016 10.016 0 0 0-7 2.877V3a1 1 0 1 0-2 0v4.5a1 1 0 0 0 1 1h4.5a1 1 0 0 0 0-2H6.218A7.98 7.98 0 0 1 20 12a1 1 0 0 0 2 0A10.012 10.012 0 0 0 12 2zm7.989 13.5h-4.5a1 1 0 0 0 0 2h2.293A7.98 7.98 0 0 1 4 12a1 1 0 0 0-2 0a9.986 9.986 0 0 0 16.989 7.133V21a1 1 0 0 0 2 0v-4.5a1 1 0 0 0-1-1z" />
                                </svg>
                            </button>
                        </div>
                        @if ($server->cloudProviderToken && !$server->isFunctional() && $hetznerServerStatus === 'off')
                            <x-forms.button wire:click.prevent='startHetznerServer' isHighlighted canGate="update"
                                :canResource="$server">
                                Power On
                            </x-forms.button>
                        @endif
                    @endif
                    @if ($isValidating)
                        <div
                            class="flex items-center gap-1.5 px-2 py-1 text-xs font-semibold rounded bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400">
                            <svg class="inline animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none"
                                viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                </path>
                            </svg>
                            <span>Validating...</span>
                        </div>
                    @endif
                    @if ($server->id === 0)
                        <x-modal-confirmation title="Confirm Server Settings Change?" buttonTitle="Save"
                            submitAction="submit" :actions="[
                                'If you misconfigure the server, you could lose a lot of functionalities of Coolify.',
                            ]" :confirmWithText="false" :confirmWithPassword="false"
                            step2ButtonText="Save" canGate="update" :canResource="$server" />
                    @else
                        <x-forms.button type="submit" canGate="update" :canResource="$server"
                            :disabled="$isValidating">Save</x-forms.button>
                        @if ($server->isFunctional())
                            <x-slide-over closeWithX fullScreen>
                                <x-slot:title>Validate & configure</x-slot:title>
                                <x-slot:content>
                                    <livewire:server.validate-and-install :server="$server" ask />
                                </x-slot:content>
                                <x-forms.button @click="slideOverOpen=true" wire:click.prevent='validateServer'
                                    isHighlighted canGate="update" :canResource="$server">
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
                @if ($isValidating)
                    <div x-data="{ slideOverOpen: true }">
                        <x-slide-over closeWithX fullScreen>
                            <x-slot:title>Validation in Progress</x-slot:title>
                            <x-slot:content>
                                <livewire:server.validate-and-install :server="$server" />
                            </x-slot:content>
                        </x-slide-over>
                    </div>
                @endif
                @if (
                    (!$isReachable || !$isUsable) &&
                        $server->id !== 0 &&
                        !$isValidating &&
                        !in_array($hetznerServerStatus, ['initializing', 'starting', 'stopping', 'off']))
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
                    <x-callout type="danger" title="Server Disabled" class="mt-4">
                        The system has disabled the server because you have exceeded the
                        number of servers for which you have paid.
                    </x-callout>
                @endif
                <div class="flex flex-col gap-2 pt-4">
                    <div class="flex flex-col gap-2 w-full lg:flex-row">
                        <x-forms.input canGate="update" :canResource="$server" id="name" label="Name" required
                            :disabled="$isValidating" />
                        <x-forms.input canGate="update" :canResource="$server" id="description" label="Description"
                            :disabled="$isValidating" />
                        @if (!$isSwarmWorker && !$isBuildServer)
                            <x-forms.input canGate="update" :canResource="$server" placeholder="https://example.com"
                                id="wildcardDomain" label="Wildcard Domain"
                                helper='A wildcard domain allows you to receive a randomly generated domain for your new applications. <br><br>For instance, if you set "https://example.com" as your wildcard domain, your applications will receive domains like "https://randomId.example.com".'
                                :disabled="$isValidating" />
                        @endif

                    </div>
                    <div class="flex flex-col gap-2 w-full lg:flex-row">
                        <x-forms.input canGate="update" :canResource="$server" type="password" id="ip"
                            label="IP Address/Domain"
                            helper="An IP Address (127.0.0.1) or domain (example.com). Make sure there is no protocol like http(s):// so you provide a FQDN not a URL."
                            required :disabled="$isValidating" />
                        <div class="flex gap-2">
                            <x-forms.input canGate="update" :canResource="$server" id="user" label="User" required
                                :disabled="$isValidating" />
                            <x-forms.input canGate="update" :canResource="$server" type="number" id="port"
                                label="Port" required :disabled="$isValidating" />
                        </div>
                    </div>
                    <div class="w-full">
                        <div class="flex items-center mb-1">
                            <label for="serverTimezone">Server Timezone</label>
                            <x-helper class="ml-2"
                                helper="Server's timezone. This is used for backups, cron jobs, etc." />
                        </div>
                        @can('update', $server)
                            @if ($isValidating)
                                <div class="relative">
                                    <div class="inline-flex relative items-center w-64">
                                        <input readonly disabled autocomplete="off"
                                            class="w-full input opacity-50 cursor-not-allowed"
                                            value="{{ $serverTimezone ?: 'No timezone set' }}"
                                            placeholder="Server Timezone">
                                        <svg class="absolute right-0 mr-2 w-4 h-4 opacity-50"
                                            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                            stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                                        </svg>
                                    </div>
                                </div>
                            @else
                                <div x-data="{
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
                                    <div class="relative">
                                        <div class="inline-flex relative items-center w-64">
                                            <input autocomplete="off"
                                                wire:dirty.class.remove='dark:focus:ring-coolgray-300 dark:ring-coolgray-300'
                                                wire:dirty.class="dark:focus:ring-warning dark:ring-warning"
                                                x-model="search" @focus="open = true" @click.away="open = false"
                                                @input="open = true" class="w-full input" :placeholder="placeholder"
                                                wire:model="serverTimezone">
                                            <svg class="absolute right-0 mr-2 w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                                                fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                                stroke="currentColor" @click="open = true">
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
                            @endif
                        @else
                            <div class="relative">
                                <div class="inline-flex relative items-center w-64">
                                    <input readonly disabled autocomplete="off"
                                        class="w-full input opacity-50 cursor-not-allowed"
                                        value="{{ $serverTimezone ?: 'No timezone set' }}" placeholder="Server Timezone">
                                    <svg class="absolute right-0 mr-2 w-4 h-4 opacity-50"
                                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                                    </svg>
                                </div>
                            </div>
                        @endcan
                    </div>

                    <div class="w-full">
                        @if (!$server->isLocalhost())
                            <div class="w-96">
                                @if ($isBuildServerLocked)
                                    <x-forms.checkbox disabled instantSave id="isBuildServer"
                                        helper="You can't use this server as a build server because it has defined resources."
                                        label="Use it as a build server?" />
                                @else
                                    <x-forms.checkbox canGate="update" :canResource="$server" instantSave
                                        id="isBuildServer" label="Use it as a build server?" :disabled="$isValidating" />
                                @endif
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
                                        <x-forms.checkbox canGate="update" :canResource="$server" instantSave
                                            type="checkbox" id="isSwarmManager"
                                            helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                                            label="Is it a Swarm Manager?" :disabled="$isValidating" />
                                    @endif

                                    @if ($server->settings->is_swarm_manager)
                                        <x-forms.checkbox disabled instantSave type="checkbox" id="isSwarmWorker"
                                            helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                                            label="Is it a Swarm Worker?" />
                                    @else
                                        <x-forms.checkbox canGate="update" :canResource="$server" instantSave
                                            type="checkbox" id="isSwarmWorker"
                                            helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                                            label="Is it a Swarm Worker?" :disabled="$isValidating" />
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
                        <x-helper helper="Sentinel reports your server's & container's health and collects metrics." />
                        @if ($server->isSentinelEnabled())
                            <div class="flex gap-2 items-center">
                                @if ($server->isSentinelLive())
                                    <x-status.running status="In sync" noLoading title="{{ $sentinelUpdatedAt }}" />
                                    <x-forms.button type="submit" canGate="update" :canResource="$server"
                                        :disabled="$isValidating">Save</x-forms.button>
                                    <x-forms.button wire:click='restartSentinel' canGate="update" :canResource="$server"
                                        :disabled="$isValidating">Restart</x-forms.button>
                                    <x-slide-over fullScreen>
                                        <x-slot:title>Sentinel Logs</x-slot:title>
                                        <x-slot:content>
                                            <livewire:project.shared.get-logs :server="$server"
                                                container="coolify-sentinel" lazy />
                                        </x-slot:content>
                                        <x-forms.button @click="slideOverOpen=true"
                                            :disabled="$isValidating">Logs</x-forms.button>
                                    </x-slide-over>
                                @else
                                    <x-status.stopped status="Out of sync" noLoading
                                        title="{{ $sentinelUpdatedAt }}" />
                                    <x-forms.button type="submit" canGate="update" :canResource="$server"
                                        :disabled="$isValidating">Save</x-forms.button>
                                    <x-forms.button wire:click='restartSentinel' canGate="update" :canResource="$server"
                                        :disabled="$isValidating">Sync</x-forms.button>
                                    <x-slide-over fullScreen>
                                        <x-slot:title>Sentinel Logs</x-slot:title>
                                        <x-slot:content>
                                            <livewire:project.shared.get-logs :server="$server"
                                                container="coolify-sentinel" lazy />
                                        </x-slot:content>
                                        <x-forms.button @click="slideOverOpen=true"
                                            :disabled="$isValidating">Logs</x-forms.button>
                                    </x-slide-over>
                                @endif
                            </div>
                        @endif
                    </div>
                    <div class="flex flex-col gap-2">
                        <div class="w-96">
                            <x-forms.checkbox canGate="update" :canResource="$server" wire:model.live="isSentinelEnabled"
                                label="Enable Sentinel" :disabled="$isValidating" />
                            @if ($server->isSentinelEnabled())
                                @if (isDev())
                                    <x-forms.checkbox canGate="update" :canResource="$server" id="isSentinelDebugEnabled"
                                        label="Enable Sentinel (with debug)" instantSave :disabled="$isValidating" />
                                @endif
                                <x-forms.checkbox canGate="update" :canResource="$server" instantSave
                                    id="isMetricsEnabled" label="Enable Metrics" :disabled="$isValidating" />
                            @else
                                @if (isDev())
                                    <x-forms.checkbox id="isSentinelDebugEnabled" label="Enable Sentinel (with debug)"
                                        disabled instantSave />
                                @endif
                                <x-forms.checkbox instantSave disabled id="isMetricsEnabled"
                                    label="Enable Metrics (enable Sentinel first)" />
                            @endif
                        </div>
                        @if (isDev() && $server->isSentinelEnabled())
                            <div class="pt-4" x-data="{
                                customImage: localStorage.getItem('sentinel_custom_docker_image_{{ $server->uuid }}') || '',
                                saveCustomImage() {
                                    localStorage.setItem('sentinel_custom_docker_image_{{ $server->uuid }}', this.customImage);
                                    $wire.set('sentinelCustomDockerImage', this.customImage);
                                }
                            }" x-init="$wire.set('sentinelCustomDockerImage', customImage)">
                                <x-forms.input x-model="customImage" @input.debounce.500ms="saveCustomImage()"
                                    placeholder="e.g., sentinel:latest or myregistry/sentinel:dev"
                                    label="Custom Sentinel Docker Image (Dev Only)"
                                    helper="Override the default Sentinel Docker image for testing. Leave empty to use the default." />
                            </div>
                        @endif
                        @if ($server->isSentinelEnabled())
                            <div class="flex flex-wrap gap-2 sm:flex-nowrap items-end">
                                <x-forms.input canGate="update" :canResource="$server" type="password" id="sentinelToken"
                                    label="Sentinel token" required helper="Token for Sentinel." :disabled="$isValidating" />
                                <x-forms.button canGate="update" :canResource="$server"
                                    wire:click="regenerateSentinelToken" :disabled="$isValidating">Regenerate</x-forms.button>
                            </div>

                            <x-forms.input canGate="update" :canResource="$server" id="sentinelCustomUrl" required
                                label="Coolify URL"
                                helper="URL to your Coolify instance. If it is empty that means you do not have a FQDN set for your Coolify instance."
                                :disabled="$isValidating" />

                            <div class="flex flex-col gap-2">
                                <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                                    <x-forms.input canGate="update" :canResource="$server"
                                        id="sentinelMetricsRefreshRateSeconds" label="Metrics rate (seconds)" required
                                        helper="Interval used for gathering metrics. Lower values result in more disk space usage."
                                        :disabled="$isValidating" />
                                    <x-forms.input canGate="update" :canResource="$server" id="sentinelMetricsHistoryDays"
                                        label="Metrics history (days)" required
                                        helper="Number of days to retain metrics data for." :disabled="$isValidating" />
                                    <x-forms.input canGate="update" :canResource="$server"
                                        id="sentinelPushIntervalSeconds" label="Push interval (seconds)" required
                                        helper="Interval at which metrics data is sent to the collector."
                                        :disabled="$isValidating" />
                                </div>
                            </div>
                        @endif
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>
