<div wire:poll.10000ms="check_status_without_notification">
    <livewire:project.shared.configuration-checker :resource="$service" />
    <x-slide-over @startservice.window="slideOverOpen = true" closeWithX fullScreen>
        <x-slot:title>Service Startup</x-slot:title>
        <x-slot:content>
            <livewire:activity-monitor header="Logs" showWaiting fullHeight />
        </x-slot:content>
    </x-slide-over>
    <h1>{{ $title }}</h1>
    <x-resources.breadcrumbs :resource="$service" :parameters="$parameters" />
    <div class="navbar-main" x-data>
        <nav class="flex flex-shrink-0 gap-6 items-center whitespace-nowrap scrollbar min-h-10">
            <a class="{{ request()->routeIs('project.service.configuration') ? 'dark:text-white' : '' }}"
                href="{{ route('project.service.configuration', $parameters) }}">
                <button>Configuration</button>
            </a>
            <a class="{{ request()->routeIs('project.service.command') ? 'dark:text-white' : '' }}"
                href="{{ route('project.service.command', $parameters) }}">
                <button>Terminal</button>
            </a>
            <x-services.links :service="$service" />
        </nav>
        @if ($service->isDeployable)
            <div class="flex flex-wrap order-first gap-2 items-center sm:order-last">
                @if (str($service->status())->contains('running'))
                    <x-dropdown>
                        <x-slot:title>
                            Advanced
                        </x-slot>
                        <div class="dropdown-item" @click="$wire.dispatch('pullAndRestartEvent')">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path
                                    d="M12.983 8.978c3.955 -.182 7.017 -1.446 7.017 -2.978c0 -1.657 -3.582 -3 -8 -3c-1.661 0 -3.204 .19 -4.483 .515m-2.783 1.228c-.471 .382 -.734 .808 -.734 1.257c0 1.22 1.944 2.271 4.734 2.74" />
                                <path
                                    d="M4 6v6c0 1.657 3.582 3 8 3c.986 0 1.93 -.067 2.802 -.19m3.187 -.82c1.251 -.53 2.011 -1.228 2.011 -1.99v-6" />
                                <path d="M4 12v6c0 1.657 3.582 3 8 3c3.217 0 5.991 -.712 7.261 -1.74m.739 -3.26v-4" />
                                <path d="M3 3l18 18" />
                            </svg>
                            Pull Latest Images & Restart
                        </div>
                    </x-dropdown>
                    <x-forms.button title="Restart" @click="$wire.dispatch('restartEvent')">
                        <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2">
                                <path d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                                <path d="M20 4v5h-5" />
                            </g>
                        </svg>
                        Restart
                    </x-forms.button>
                    <x-modal-confirmation title="Confirm Service Stopping?" buttonTitle="Stop" submitAction="stop"
                        :checkboxes="$checkboxes" :actions="[__('service.stop'), __('resource.non_persistent')]" :confirmWithText="false" :confirmWithPassword="false"
                        step1ButtonText="Continue" step2ButtonText="Stop Service" :dispatchEvent="true"
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
                            Stop
                        </x-slot:button-title>
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
                    <x-modal-confirmation title="Confirm Service Stopping?" buttonTitle="Stop" submitAction="stop"
                        :checkboxes="$checkboxes" :actions="[__('service.stop'), __('resource.non_persistent')]" :confirmWithText="false" :confirmWithPassword="false"
                        step1ButtonText="Continue" step2ButtonText="Stop Service" :dispatchEvent="true"
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
                            Stop
                        </x-slot:button-title>
                    </x-modal-confirmation>
                @elseif (str($service->status())->contains('exited'))
                    <button wire:click='stop(true)' class="gap-2 button">
                        <svg class="w-5 h-5" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
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
                    <x-modal-confirmation title="Confirm Service Stopping?" buttonTitle="Stop" submitAction="stop"
                        :checkboxes="$checkboxes" :actions="[__('service.stop'), __('resource.non_persistent')]" :confirmWithText="false" :confirmWithPassword="false"
                        step1ButtonText="Continue" step2ButtonText="Stop Service" :dispatchEvent="true"
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
                            Stop
                        </x-slot:button-title>
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
        @else
            <div class="flex flex-wrap order-first gap-2 items-center sm:order-last">
                <div class="text-error">
                    Unable to deploy. <a
                        class="underline font-bold cursor-pointer"
                        @click.prevent="activeTab = 'environment-variables'; window.location.hash = 'environment-variables'">
                        Required environment variables missing.</a>
                </div>
            </div>
        @endif
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
                $wire.$dispatch('info', 'Service restart in progress.');
                $wire.$call('restart');
            });
            $wire.$on('pullAndRestartEvent', () => {
                $wire.$dispatch('info', 'Pulling new images.');
                $wire.$call('pullAndRestartEvent');
            });
            $wire.on('imagePulled', () => {
                window.dispatchEvent(new CustomEvent('startservice'));
                $wire.$dispatch('info', 'Restarting service.');
            });
        </script>
    @endscript

</div>
