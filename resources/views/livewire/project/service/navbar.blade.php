<div>
    <livewire:project.shared.configuration-checker :resource="$service" />
    <x-slide-over @startservice.window="slideOverOpen = true" closeWithX fullScreen>
        <x-slot:title>Service Startup</x-slot:title>
        <x-slot:content>
            <livewire:activity-monitor header="Logs" showWaiting fullHeight />
        </x-slot:content>
    </x-slide-over>
    <h1>Configuration</h1>
    <x-resources.breadcrumbs :resource="$service" :parameters="$parameters" />
    <div class="navbar-main" x-data>
        <nav class="flex items-center flex-shrink-0 gap-6 scrollbar min-h-10 whitespace-nowrap">
            <a class="{{ request()->routeIs('project.service.configuration') ? 'dark:text-white' : '' }}"
                href="{{ route('project.service.configuration', $parameters) }}">
                <button>Configuration</button>
            </a>
            <x-services.links :service="$service" />
        </nav>
        <div class="flex flex-wrap items-center order-first gap-2 sm:order-last">
            @if (str($service->status())->contains('running'))
                <button @click="$wire.dispatch('restartEvent')" class="gap-2 button">
                    <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2">
                            <path d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                            <path d="M20 4v5h-5" />
                        </g>
                    </svg>
                    Pull Latest Images & Restart
                </button>
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
                        Stop
                    </x-slot:button-title>
                    This service will be stopped. <br>Please think again.
                </x-modal-confirmation>
            @elseif (str($service->status())->contains('degraded'))
                <button @click="$wire.dispatch('startEvent')" class="gap-2 button">
                    <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2">
                            <path d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                            <path d="M20 4v5h-5" />
                        </g>
                    </svg>
                    Restart Degraded Services
                </button>
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
                        Stop
                    </x-slot:button-title>
                    This service will be stopped. <br>Please think again.
                </x-modal-confirmation>
            @elseif (str($service->status())->contains('exited'))
                <button wire:click='stop(true)' class="gap-2 button">
                    <svg class="w-5 h-5 " viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
                        <path fill="red" d="M26 20h-6v-2h6zm4 8h-6v-2h6zm-2-4h-6v-2h6z" />
                        <path fill="red"
                            d="M17.003 20a4.895 4.895 0 0 0-2.404-4.173L22 3l-1.73-1l-7.577 13.126a5.699 5.699 0 0 0-5.243 1.503C3.706 20.24 3.996 28.682 4.01 29.04a1 1 0 0 0 1 .96h14.991a1 1 0 0 0 .6-1.8c-3.54-2.656-3.598-8.146-3.598-8.2Zm-5.073-3.003A3.11 3.11 0 0 1 15.004 20c0 .038.002.208.017.469l-5.9-2.624a3.8 3.8 0 0 1 2.809-.848ZM15.45 28A5.2 5.2 0 0 1 14 25h-2a6.5 6.5 0 0 0 .968 3h-2.223A16.617 16.617 0 0 1 10 24H8a17.342 17.342 0 0 0 .665 4H6c.031-1.836.29-5.892 1.803-8.553l7.533 3.35A13.025 13.025 0 0 0 17.596 28Z" />
                    </svg>
                    Force Cleanup Containers
                </button>
                <button @click="$wire.dispatch('startEvent')" class="gap-2 button">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                        stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M7 4v16l13 -8z" />
                    </svg>
                    Deploy
                </button>
            @else
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
                        Stop
                    </x-slot:button-title>
                    This service will be stopped. <br>Please think again.
                </x-modal-confirmation>
                <button @click="$wire.dispatch('startEvent')" class="gap-2 button">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                        stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M7 4v16l13 -8z" />
                    </svg>
                    Deploy
                </button>
            @endif
        </div>
    </div>
    @script
        <script>
            $wire.$on('stopEvent', () => {
                $wire.$dispatch('info', 'Stopping service.');
                $wire.$call('stop');
            });
            $wire.$on('startEvent', () => {
                window.dispatchEvent(new CustomEvent('startservice'));
                $wire.$call('start');
            });
            $wire.$on('restartEvent', () => {
                $wire.$dispatch('info', 'Pulling new images.');
                $wire.$call('restart');
            });
            $wire.on('imagePulled', () => {
                window.dispatchEvent(new CustomEvent('startservice'));
                $wire.$dispatch('info', 'Restarting service.');
            });
        </script>
    @endscript

</div>
