<div>
    <x-modal yesOrNo modalId="stopProxy" modalTitle="Stop Proxy" action="stop">
        <x-slot:modalBody>
            <p>This proxy will be stopped. It is not reversible. <br>All resources will be unavailable.
                <br>Please think
                again.
            </p>
        </x-slot:modalBody>
    </x-modal>
    @if (is_null(data_get($server, 'proxy.type')) || data_get($server, 'proxy.type') !== 'NONE')
        @if (data_get($server, 'proxy.status') === 'running')
            <div class="flex gap-4">
                <button>
                    <a target="_blank" href="{{ base_url(false) }}:8080">
                        Traefik Dashboard
                        <x-external-link />
                    </a>
                </button>
                <x-forms.button isModal noStyle modalId="stopProxy"
                    class="flex items-center gap-2 cursor-pointer hover:text-white text-neutral-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-error" viewBox="0 0 24 24" stroke-width="2"
                        stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                        <path d="M6 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z"></path>
                        <path d="M14 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z"></path>
                    </svg>
                    Stop
                </x-forms.button>
            </div>
        @else
            <button wire:click='startProxy' onclick="startProxy.showModal()"
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
</div>
