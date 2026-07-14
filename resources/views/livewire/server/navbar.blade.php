<div class="pb-0 md:pb-6">
    <x-slide-over @startproxy.window="slideOverOpen = true" fullScreen closeWithX>
        <x-slot:title>Proxy Startup Logs</x-slot:title>
        <x-slot:content>
            @if ($server->id === 0)
                <div class="mb-4 p-3 text-sm bg-warning/10 border border-warning/30 rounded-lg text-warning">
                    <span class="font-semibold">Note:</span> This is the localhost server where Coolify runs.
                    During proxy restart, the connection may be temporarily lost.
                    If logs stop updating, please refresh the browser after a few minutes.
                </div>
            @endif
            <livewire:activity-monitor header="Logs" fullHeight />
        </x-slot:content>
    </x-slide-over>
        <h1>Server</h1>
    <div class="pt-2 pb-4 md:pb-10">
        <div class="flex-col md:flex-row flex gap-2">
            <div data-testid="server-subtitle" class="text-xs lg:text-sm min-w-0 truncate text-neutral-600 dark:text-neutral-400">
                {{ data_get($server, 'name') }}
            </div>
            @php
                $showSentinelStatus = $server->isFunctional() && $server->isSentinelEnabled();
            @endphp
            @if ($server->proxySet() || $showSentinelStatus)
                <div data-testid="server-status-summary" class="flex flex-wrap items-center gap-1">
                    @if ($server->proxySet())
                        <div class="flex items-center gap-1">
                            @if ($proxyStatus === 'running')
                                <x-status-badge label="Proxy" status="Running" type="success" />
                            @elseif ($proxyStatus === 'restarting')
                                <x-status-badge label="Proxy" status="Restarting" type="warning" />
                            @elseif ($proxyStatus === 'stopping')
                                <x-status-badge label="Proxy" status="Stopping" type="warning" />
                            @elseif ($proxyStatus === 'starting')
                                <x-status-badge label="Proxy" status="Starting" type="warning" />
                            @elseif (data_get($server, 'proxy.force_stop'))
                                <x-status-badge wire:loading.remove wire:target="checkProxy" label="Proxy"
                                    status="Force stopped" type="error" />
                            @elseif ($proxyStatus === 'exited')
                                <x-status-badge wire:loading.remove wire:target="checkProxy" label="Proxy" status="Exited"
                                    type="error" />
                            @endif
                            <x-status-badge wire:loading wire:target="checkProxy" label="Proxy" status="Checking..."
                                type="warning" />
                        </div>
                    @endif
                    @if ($showSentinelStatus)
                        @if ($server->isSentinelLive())
                            <x-status-badge label="Sentinel" status="In sync" type="success" />
                        @else
                            <x-status-badge label="Sentinel" status="Out of sync" type="error" />
                        @endif
                    @endif
                    @if ($server->proxySet())
                        <x-status-badge as="button" wire:target="checkProxyStatus" wire:loading.attr="disabled"
                            wire:click='checkProxyStatus' status="Refresh" type="neutral" title="Refresh Status"
                            aria-label="Refresh proxy status"
                            class="min-w-[4.5rem] justify-center cursor-pointer border-transparent hover:bg-neutral-200 disabled:cursor-wait disabled:opacity-70 dark:hover:bg-coolgray-300" />
                    @endif
                </div>
            @endif
        </div>
    </div>
    <div class="navbar-main">
        <nav
            class="scrollbar hidden min-h-10 w-full flex-nowrap items-center gap-6 overflow-x-scroll overflow-y-hidden pb-1 whitespace-nowrap md:flex md:w-auto md:overflow-visible">
            <a class="{{ request()->routeIs('server.show') ? 'dark:text-white' : '' }}" href="{{ route('server.show', [
    'server_uuid' => data_get($server, 'uuid'),
]) }}" {{ wireNavigate() }}>
                Configuration
            </a>

            @if (!$server->isSwarmWorker() && !$server->settings->is_build_server)
                        <a class="{{ request()->routeIs('server.proxy') || request()->routeIs('server.proxy.*') ? 'dark:text-white' : '' }} flex items-center gap-1" href="{{ route('server.proxy', [
                    'server_uuid' => data_get($server, 'uuid'),
                ]) }}" {{ wireNavigate() }}>
                            Proxy
                            @if ($this->hasTraefikOutdated)
                                <svg class="w-4 h-4 text-warning" viewBox="0 0 256 256" xmlns="http://www.w3.org/2000/svg">
                                    <path fill="currentColor"
                                        d="M236.8 188.09L149.35 36.22a24.76 24.76 0 0 0-42.7 0L19.2 188.09a23.51 23.51 0 0 0 0 23.72A24.35 24.35 0 0 0 40.55 224h174.9a24.35 24.35 0 0 0 21.33-12.19a23.51 23.51 0 0 0 .02-23.72m-13.87 15.71a8.5 8.5 0 0 1-7.48 4.2H40.55a8.5 8.5 0 0 1-7.48-4.2a7.59 7.59 0 0 1 0-7.72l87.45-151.87a8.75 8.75 0 0 1 15 0l87.45 151.87a7.59 7.59 0 0 1-.04 7.72M120 144v-40a8 8 0 0 1 16 0v40a8 8 0 0 1-16 0m20 36a12 12 0 1 1-12-12a12 12 0 0 1 12 12" />
                                </svg>
                            @endif
                        </a>
            @endif
            @if ($server->isFunctional() && !$server->isSwarm() && !$server->settings->is_build_server && auth()->user()?->can('viewSentinel', $server))
                        <a class="{{ request()->routeIs('server.sentinel') || request()->routeIs('server.sentinel.*') ? 'dark:text-white' : '' }} flex items-center gap-1" href="{{ route('server.sentinel', [
                    'server_uuid' => data_get($server, 'uuid'),
                ]) }}" {{ wireNavigate() }}>
                            Sentinel
                            @if ($server->isSentinelEnabled() && !$server->isSentinelLive())
                                <svg class="w-4 h-4 text-warning" viewBox="0 0 256 256" xmlns="http://www.w3.org/2000/svg">
                                    <path fill="currentColor"
                                        d="M236.8 188.09L149.35 36.22a24.76 24.76 0 0 0-42.7 0L19.2 188.09a23.51 23.51 0 0 0 0 23.72A24.35 24.35 0 0 0 40.55 224h174.9a24.35 24.35 0 0 0 21.33-12.19a23.51 23.51 0 0 0 .02-23.72m-13.87 15.71a8.5 8.5 0 0 1-7.48 4.2H40.55a8.5 8.5 0 0 1-7.48-4.2a7.59 7.59 0 0 1 0-7.72l87.45-151.87a8.75 8.75 0 0 1 15 0l87.45 151.87a7.59 7.59 0 0 1-.04 7.72M120 144v-40a8 8 0 0 1 16 0v40a8 8 0 0 1-16 0m20 36a12 12 0 1 1-12-12a12 12 0 0 1 12 12" />
                                </svg>
                            @endif
                        </a>
            @endif
            <a class="{{ request()->routeIs('server.resources') ? 'dark:text-white' : '' }}" href="{{ route('server.resources', [
    'server_uuid' => data_get($server, 'uuid'),
]) }}" {{ wireNavigate() }}>
                Resources
            </a>
            @can('canAccessTerminal')
                        <a class="{{ request()->routeIs('server.command') ? 'dark:text-white' : '' }}" href="{{ route('server.command', [
                    'server_uuid' => data_get($server, 'uuid'),
                ]) }}">
                            Terminal
                        </a>
            @endcan
            @can('update', $server)
                        <a class="{{ request()->routeIs('server.security.patches') ? 'dark:text-white' : '' }}" href="{{ route('server.security.patches', [
                    'server_uuid' => data_get($server, 'uuid'),
                ]) }}" {{ wireNavigate() }}>
                            Security
                        </a>
            @endcan
        </nav>
        <div class="order-first w-full md:order-last md:w-auto">
            <div>
                @if ($server->proxySet())
                    @can('manageProxy', $server)
                    <div id="server-mobile-actions" class="mt-2 mb-3 md:hidden">
                        <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Actions</div>
                    @if ($proxyStatus === 'running')
                            <div class="flex flex-nowrap gap-2 overflow-x-auto">
                                <button type="button" class="button shrink-0"
                                    @click="document.getElementById('server-mobile-restart-proxy-trigger')?.click()">
                                    <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                            stroke-width="2">
                                            <path d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                                            <path d="M20 4v5h-5" />
                                        </g>
                                    </svg>
                                    Restart Proxy
                                </button>
                                <button type="button" class="button shrink-0 text-error"
                                    @click="document.getElementById('server-mobile-stop-proxy-trigger')?.click()">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-error" viewBox="0 0 24 24"
                                        stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                        <path d="M6 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                                        </path>
                                        <path
                                            d="M14 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                                        </path>
                                    </svg>
                                    Stop Proxy
                                </button>
                                @if ($traefikDashboardAvailable)
                                    <a class="button shrink-0" target="_blank" href="http://{{ $serverIp }}:8080">
                                        Traefik Dashboard
                                        <x-external-link />
                                    </a>
                                @endif
                            </div>
                    </div>
                            <x-modal-confirmation title="Confirm Proxy Restart?" buttonTitle="Restart Proxy"
                                submitAction="restart" :actions="[
                        'This proxy will be stopped and started again.',
                        'All resources hosted on coolify will be unavailable during the restart.',
                    ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Restart Proxy"
                                :dispatchEvent="true" dispatchEventType="restartEvent">
                                <x-slot:trigger>
                                    <button id="server-mobile-restart-proxy-trigger" type="button" class="hidden">Restart Proxy</button>
                                </x-slot:trigger>
                            </x-modal-confirmation>
                            <x-modal-confirmation title="Confirm Proxy Stopping?" buttonTitle="Stop Proxy"
                                submitAction="stop(true)" :actions="[
                        'The coolify proxy will be stopped.',
                        'All resources hosted on coolify will be unavailable.',
                    ]" :confirmWithText="false"
                                :confirmWithPassword="false" step2ButtonText="Stop Proxy" :dispatchEvent="true"
                                dispatchEventType="stopEvent">
                                <x-slot:trigger>
                                    <button id="server-mobile-stop-proxy-trigger" type="button" class="hidden">Stop Proxy</button>
                                </x-slot:trigger>
                            </x-modal-confirmation>
                            <div class="hidden gap-2 md:flex">
                                <div class="mt-1" wire:loading wire:target="loadProxyConfiguration">
                                    <x-loading text="Checking Traefik dashboard" />
                                </div>
                                @if ($traefikDashboardAvailable)
                                    <button>
                                        <a target="_blank" href="http://{{ $serverIp }}:8080">
                                            Traefik Dashboard
                                            <x-external-link />
                                        </a>
                                    </button>
                                @endif
                                <x-modal-confirmation title="Confirm Proxy Restart?" buttonTitle="Restart Proxy"
                                    submitAction="restart" :actions="[
                            'This proxy will be stopped and started again.',
                            'All resources hosted on coolify will be unavailable during the restart.',
                        ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Restart Proxy"
                                    :dispatchEvent="true" dispatchEventType="restartEvent">
                                    <x-slot:button-title>
                                        <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                                stroke-width="2">
                                                <path d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                                                <path d="M20 4v5h-5" />
                                            </g>
                                        </svg>
                                        Restart Proxy
                                    </x-slot:button-title>
                                </x-modal-confirmation>
                                <x-modal-confirmation title="Confirm Proxy Stopping?" buttonTitle="Stop Proxy"
                                    submitAction="stop(true)" :actions="[
                            'The coolify proxy will be stopped.',
                            'All resources hosted on coolify will be unavailable.',
                        ]" :confirmWithText="false"
                                    :confirmWithPassword="false" step2ButtonText="Stop Proxy" :dispatchEvent="true"
                                    dispatchEventType="stopEvent">
                                    <x-slot:button-title>
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-error" viewBox="0 0 24 24"
                                            stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                            <path d="M6 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                                            </path>
                                            <path
                                                d="M14 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                                            </path>
                                        </svg>
                                        Stop Proxy
                                    </x-slot:button-title>
                                </x-modal-confirmation>
                            </div>
                    @else
                        <button @click="$wire.dispatch('checkProxyEvent')" class="gap-2 button">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M7 4v16l13 -8z" />
                            </svg>
                            Start Proxy
                        </button>
                    </div>
                    @endif
                    @endcan
                @endif
                @script
                <script>
                    $wire.$on('checkProxyEvent', () => {
                        try {
                            $wire.$call('checkProxy');
                        } catch (error) {
                            console.error(error);
                            $wire.$dispatch('error', 'Failed to check proxy status. Please try again.');
                        }
                    });
                    $wire.$on('restartEvent', () => {
                        if ($wire.restartInitiated) return;
                        window.dispatchEvent(new CustomEvent('startproxy'))
                        $wire.$call('restart');
                    });
                    $wire.$on('startProxy', () => {
                        window.dispatchEvent(new CustomEvent('startproxy'))
                        $wire.$call('startProxy');
                    });
                    $wire.$on('stopEvent', () => {
                        $wire.$call('stop');
                    });
                </script>
                @endscript
            </div>
        </div>
    </div>
</div>
