@php use App\Enums\ProxyTypes; @endphp
<div>
    @if (
        $server->proxyType() !== 'NONE' &&
            $server->isFunctional() &&
            !$server->isSwarmWorker() &&
            !$server->settings->is_build_server)
        <x-slide-over closeWithX fullScreen @startproxy.window="slideOverOpen = true">
            <x-slot:title>Proxy Status</x-slot:title>
            <x-slot:content>
                <livewire:activity-monitor header="Logs" />
            </x-slot:content>
        </x-slide-over>
        @if (data_get($server, 'proxy.status') === 'running')
            <div class="flex gap-2">
                @if (
                    $currentRoute === 'server.proxy' &&
                        $traefikDashboardAvailable &&
                        $server->proxyType() === ProxyTypes::TRAEFIK->value)
                    <button>
                        <a target="_blank" href="http://{{ $serverIp }}:8080">
                            Traefik Dashboard
                            <x-external-link />
                        </a>
                    </button>
                @endif
                <x-modal-confirmation @click="$wire.dispatch('restartEvent')">
                    <x-slot:button-title>
                        <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2">
                                <path d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                                <path d="M20 4v5h-5" />
                            </g>
                        </svg>
                        Restart Proxy
                    </x-slot:button-title>
                    This proxy will be stopped and started. It is not reversible. <br>All resources will be unavailable
                    during the restart. <br>Please think again.
                </x-modal-confirmation>
                <x-modal-confirmation @click="$wire.dispatch('stopEvent')">
                    <x-slot:button-title>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-error" viewBox="0 0 24 24"
                            stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                            <path d="M6 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                            </path>
                            <path d="M14 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                            </path>
                        </svg>
                        Stop Proxy
                    </x-slot:button-title>
                    This proxy will be stopped. It is not reversible. <br>All resources will be unavailable.
                    <br>Please think again.
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
        @endif
    @endif
    @script
        <script>
            $wire.$on('checkProxyEvent', () => {
                $wire.$dispatch('info', 'Starting proxy.');
                $wire.$call('checkProxy');
            });
            $wire.$on('restartEvent', () => {
                $wire.$dispatch('info', 'Restarting proxy.');
                $wire.$call('restart');
            });
            $wire.$on('proxyChecked', () => {
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
