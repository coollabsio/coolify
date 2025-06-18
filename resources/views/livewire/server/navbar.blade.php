<div class="pb-6">
    <x-slide-over @startproxy.window="slideOverOpen = true" fullScreen>
        <x-slot:title>Proxy Startup Logs</x-slot:title>
        <x-slot:content>
            <livewire:activity-monitor header="Logs" fullHeight />
        </x-slot:content>
    </x-slide-over>
    <div class="flex items-center gap-2">
        <h1>Server</h1>
        @if ($server->proxySet())
            <div class="flex">
                <div class="flex items-center">
                    @if ($proxyStatus === 'running')
                        <x-status.running status="Proxy Running" noLoading />
                    @elseif ($proxyStatus === 'restarting')
                        <x-status.restarting status="Proxy Restarting" noLoading />
                    @elseif ($proxyStatus === 'stopping')
                        <x-status.restarting status="Proxy Stopping" noLoading />
                    @elseif ($proxyStatus === 'starting')
                        <x-status.restarting status="Proxy Starting" noLoading />
                    @elseif (data_get($server, 'proxy.force_stop'))
                        <div wire:loading.remove wire:target="checkProxy">
                            <x-status.stopped status="Proxy Stopped (Force Stop)" noLoading />
                        </div>
                    @elseif ($proxyStatus === 'exited')
                        <div wire:loading.remove wire:target="checkProxy">
                            <x-status.stopped status="Proxy Exited" noLoading />
                        </div>
                    @endif
                    <div wire:loading wire:target="checkProxy" class="badge badge-warning"></div>
                    <div wire:loading wire:target="checkProxy"
                        class="pl-2 pr-1 text-xs font-bold tracking-wider text-warning">
                        Checking Ports Availability...
                    </div>
                    @if ($proxyStatus !== 'exited')
                        <button wire:loading.remove title="Refresh Status" wire:click='checkProxyStatus'
                            class="mx-1 dark:hover:fill-white fill-black dark:fill-warning">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M12 2a10.016 10.016 0 0 0-7 2.877V3a1 1 0 1 0-2 0v4.5a1 1 0 0 0 1 1h4.5a1 1 0 0 0 0-2H6.218A7.98 7.98 0 0 1 20 12a1 1 0 0 0 2 0A10.012 10.012 0 0 0 12 2zm7.989 13.5h-4.5a1 1 0 0 0 0 2h2.293A7.98 7.98 0 0 1 4 12a1 1 0 0 0-2 0a9.986 9.986 0 0 0 16.989 7.133V21a1 1 0 0 0 2 0v-4.5a1 1 0 0 0-1-1z" />
                            </svg>
                        </button>
                        <button wire:loading title="Refreshing Status" wire:click='checkProxyStatus'
                            class="mx-1 dark:hover:fill-white fill-black dark:fill-warning">
                            <svg class="w-4 h-4 animate-spin" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M12 2a10.016 10.016 0 0 0-7 2.877V3a1 1 0 1 0-2 0v4.5a1 1 0 0 0 1 1h4.5a1 1 0 0 0 0-2H6.218A7.98 7.98 0 0 1 20 12a1 1 0 0 0 2 0A10.012 10.012 0 0 0 12 2zm7.989 13.5h-4.5a1 1 0 0 0 0 2h2.293A7.98 7.98 0 0 1 4 12a1 1 0 0 0-2 0a9.986 9.986 0 0 0 16.989 7.133V21a1 1 0 0 0 2 0v-4.5a1 1 0 0 0-1-1z" />
                            </svg>
                        </button>
                    @endif
                </div>
            </div>
        @endif
    </div>
    <div class="subtitle">{{ data_get($server, 'name') }}</div>
    <div class="navbar-main">
        <nav
            class="flex items-center gap-6 overflow-x-scroll sm:overflow-x-hidden scrollbar min-h-10 whitespace-nowrap pt-2">
            <a class="{{ request()->routeIs('server.show') ? 'dark:text-white' : '' }}"
                href="{{ route('server.show', [
                    'server_uuid' => data_get($server, 'uuid'),
                ]) }}">
                <button>Configuration</button>
            </a>

            @if (!$server->isSwarmWorker() && !$server->settings->is_build_server)
                <a class="{{ request()->routeIs('server.proxy') ? 'dark:text-white' : '' }}"
                    href="{{ route('server.proxy', [
                        'server_uuid' => data_get($server, 'uuid'),
                    ]) }}">
                    <button>Proxy</button>
                </a>
            @endif
            <a class="{{ request()->routeIs('server.resources') ? 'dark:text-white' : '' }}"
                href="{{ route('server.resources', [
                    'server_uuid' => data_get($server, 'uuid'),
                ]) }}">
                <button>Resources</button>
            </a>
            <a class="{{ request()->routeIs('server.command') ? 'dark:text-white' : '' }}"
                href="{{ route('server.command', [
                    'server_uuid' => data_get($server, 'uuid'),
                ]) }}">
                <button>Terminal</button>
            </a>
            <a class="{{ request()->routeIs('server.security.patches') ? 'dark:text-white' : '' }}"
                href="{{ route('server.security.patches', [
                    'server_uuid' => data_get($server, 'uuid'),
                ]) }}">
                <button>Security</button>
            </a>
        </nav>
        <div class="order-first sm:order-last">
            <div>
                @if ($server->proxySet())
                    <x-slide-over fullScreen @startproxy.window="slideOverOpen = true">
                        <x-slot:title>Proxy Status</x-slot:title>
                        <x-slot:content>
                            <livewire:activity-monitor header="Logs" />
                        </x-slot:content>
                    </x-slide-over>
                    @if ($proxyStatus === 'running')
                        <div class="flex gap-2">
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
                                ]" :confirmWithText="false" :confirmWithPassword="false"
                                step2ButtonText="Restart Proxy" :dispatchEvent="true" dispatchEventType="restartEvent">
                                <x-slot:button-title>
                                    <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g fill="none" stroke="currentColor" stroke-linecap="round"
                                            stroke-linejoin="round" stroke-width="2">
                                            <path
                                                d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
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
                                ]" :confirmWithText="false" :confirmWithPassword="false"
                                step2ButtonText="Stop Proxy" :dispatchEvent="true" dispatchEventType="stopEvent">
                                <x-slot:button-title>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-error"
                                        viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"
                                        stroke-linecap="round" stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                        <path
                                            d="M6 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
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
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning"
                                viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M7 4v16l13 -8z" />
                            </svg>
                            Start Proxy
                        </button>
                    @endif
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
                            $wire.$dispatch('info', 'Initiating proxy restart.');
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
