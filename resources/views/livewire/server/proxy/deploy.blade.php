<div>
    <x-modal yesOrNo modalId="stopProxy" modalTitle="Stop Proxy" action="stop">
        <x-slot:modalBody>
            <p>This proxy will be stopped. It is not reversible. <br>All resources will be unavailable.
                <br>Please think
                again.
            </p>
        </x-slot:modalBody>
    </x-modal>
    <x-modal yesOrNo modalId="restartProxy" modalTitle="Restart Proxy" action="restart">
        <x-slot:modalBody>
            <p>This proxy will be stopped and started. It is not reversible. <br>All resources will be unavailable
                during the restart.
                <br>Please think
                again.
            </p>
        </x-slot:modalBody>
    </x-modal>
    @if ($server->isFunctional() && data_get($server, 'proxy.type') !== 'NONE')
        @if (data_get($server, 'proxy.status') === 'running')
            <div class="flex gap-4">
                @if ($currentRoute === 'server.proxy' && $traefikDashboardAvailable)
                    <button>
                        <a target="_blank" href="http://{{ $serverIp }}:8080">
                            Traefik Dashboard
                            <x-external-link />
                        </a>
                    </button>
                @endif
                <x-forms.button isModal noStyle modalId="restartProxy"
                    class="flex items-center gap-2 cursor-pointer hover:text-white text-neutral-400">
                    <svg class="w-5 h-5 text-warning" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2">
                            <path d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                            <path d="M20 4v5h-5" />
                        </g>
                    </svg>
                    Restart Proxy
                </x-forms.button>
                <x-forms.button isModal noStyle modalId="stopProxy"
                    class="flex items-center gap-2 cursor-pointer hover:text-white text-neutral-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-error" viewBox="0 0 24 24"
                        stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                        <path d="M6 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z"></path>
                        <path d="M14 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z"></path>
                    </svg>
                    Stop Proxy
                </x-forms.button>
            </div>
        @else
            <button x-on:click="$wire.dispatch('checkProxy')"
                class="flex items-center gap-2 cursor-pointer hover:text-white text-neutral-400">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-warning" viewBox="0 0 24 24"
                    stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path d="M7 4v16l13 -8z" />
                </svg>
                Start Proxy
            </button>
        @endif
    @endif
    <script>
        Livewire.on('proxyChecked', () => {
            startProxy.showModal();
            window.Livewire.dispatch('startProxy');
        })
    </script>
</div>
