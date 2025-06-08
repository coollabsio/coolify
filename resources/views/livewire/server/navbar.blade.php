<div class="pb-6">
    <x-modal modalId="startProxy">
        <x-slot:modalBody>
            <livewire:activity-monitor header="Proxy Startup Logs" />
        </x-slot:modalBody>
        <x-slot:modalSubmit>
            <x-forms.button onclick="startProxy.close()" type="submit">
                Close
            </x-forms.button>
        </x-slot:modalSubmit>
    </x-modal>
    <div class="flex items-center gap-2">
        <h1>Server</h1>
        @if ($server->proxySet())
            <div class="flex gap-2">
                @if (data_get($server, 'proxy.force_stop', false) === false)
                    <x-forms.button wire:click='checkProxyStatus()' :disabled="$isChecking" wire:loading.attr="disabled"
                        wire:target="checkProxyStatus">
                        <span wire:loading.remove wire:target="checkProxyStatus">Refresh</span>
                        <span wire:loading wire:target="checkProxyStatus">Checking...</span>
                    </x-forms.button>
                @endif

                <div wire:loading.remove wire:target="checkProxyStatus" class="flex items-center gap-2">
                    @if (data_get($server, 'proxy.status') === 'running')
                        <x-status.running status="Proxy Running" />
                    @elseif (data_get($server, 'proxy.status') === 'restarting')
                        <x-status.restarting status="Proxy Restarting" />
                    @elseif (data_get($server, 'proxy.force_stop'))
                        <x-status.stopped status="Proxy Stopped" />
                    @elseif (data_get($server, 'proxy.status') === 'exited')
                        <x-status.stopped status="Proxy Exited" />
                    @else
                        <x-status.stopped status="Proxy Not Running" />
                    @endif
                </div>
            </div>

        @endif
    </div>
    <div class="subtitle">{{ data_get($server, 'name') }}</div>
    <div class="navbar-main">
        <nav
            class="flex items-center gap-6 overflow-x-scroll sm:overflow-x-hidden scrollbar min-h-10 whitespace-nowrap">
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
            @php use App\Enums\ProxyTypes; @endphp
            <div>
                @if ($server->proxySet())
                    <x-slide-over fullScreen @startproxy.window="slideOverOpen = true">
                        <x-slot:title>Proxy Status</x-slot:title>
                        <x-slot:content>
                            <livewire:activity-monitor header="Logs" />
                        </x-slot:content>
                    </x-slide-over>
                    @if (data_get($server, 'proxy.status') === 'running')
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
                            $wire.$dispatch('info', 'Checking if the required ports are not used by other services.');
                            $wire.$call('checkProxy');
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
                            $wire.$dispatch('info', 'Stopping proxy.');
                            $wire.$call('stop');
                        });
                    </script>
                @endscript
            </div>
        </div>
    </div>
</div>
