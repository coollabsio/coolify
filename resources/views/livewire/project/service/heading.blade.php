<div wire:poll.10000ms="checkStatus" class="pb-6">
    @php
        $servicePageItems = [
            ['label' => 'Configuration', 'route' => 'project.service.configuration', 'active' => request()->routeIs('project.service.configuration')],
            ['label' => 'Logs', 'route' => 'project.service.logs', 'active' => request()->routeIs('project.service.logs')],
            ['label' => 'Terminal', 'route' => 'project.service.command', 'active' => request()->routeIs('project.service.command'), 'navigate' => false, 'visible' => auth()->user()?->can('canAccessTerminal')],
        ];

        $serviceConfigurationItems = [
            ['label' => 'General', 'route' => 'project.service.configuration', 'active' => request()->routeIs('project.service.configuration')],
            ['label' => 'Environment Variables', 'route' => 'project.service.environment-variables', 'active' => request()->routeIs('project.service.environment-variables')],
            ['label' => 'Persistent Storages', 'route' => 'project.service.storages', 'active' => request()->routeIs('project.service.storages')],
            ['label' => 'Scheduled Tasks', 'route' => 'project.service.scheduled-tasks.show', 'active' => request()->routeIs('project.service.scheduled-tasks.show', 'project.service.scheduled-tasks')],
            ['label' => 'Webhooks', 'route' => 'project.service.webhooks', 'active' => request()->routeIs('project.service.webhooks')],
            ['label' => 'Resource Operations', 'route' => 'project.service.resource-operations', 'active' => request()->routeIs('project.service.resource-operations')],
            ['label' => 'Tags', 'route' => 'project.service.tags', 'active' => request()->routeIs('project.service.tags')],
            ['label' => 'Danger Zone', 'route' => 'project.service.danger', 'active' => request()->routeIs('project.service.danger')],
        ];
        $serviceResourceItems = [];

        if (filled(data_get($parameters, 'stack_service_uuid'))) {
            $serviceResourceItems = [
                ['label' => 'Back', 'route' => 'project.service.configuration', 'active' => false, 'parameters' => [...$parameters, 'stack_service_uuid' => null]],
                ['label' => 'General', 'route' => 'project.service.index', 'active' => request()->routeIs('project.service.index')],
                ['label' => 'Advanced', 'route' => 'project.service.index.advanced', 'active' => request()->routeIs('project.service.index.advanced')],
                ['label' => 'Backups', 'route' => 'project.service.database.backups', 'active' => request()->routeIs('project.service.database.backups')],
                ['label' => 'Import Backup', 'route' => 'project.service.database.import', 'active' => request()->routeIs('project.service.database.import')],
            ];
        }

        $servicePageItems = array_values(array_filter($servicePageItems, fn (array $item): bool => $item['visible'] ?? true));
        $serviceResourceItems = array_values(array_filter($serviceResourceItems, fn (array $item): bool => $item['visible'] ?? true));
        $activeServiceResourceItem = collect($serviceResourceItems)->firstWhere('active', true);
        $activeServiceConfigurationItem = collect($serviceConfigurationItems)->firstWhere('active', true);
        $activeServicePageItem = collect($servicePageItems)->firstWhere('active', true);
        $activeServiceMobileItem = $activeServiceResourceItem ?? $activeServiceConfigurationItem ?? $activeServicePageItem ?? $servicePageItems[0];
        $activeServiceMobileGroup = $activeServiceResourceItem ? 'resource' : ($activeServiceConfigurationItem ? 'configuration' : 'service');
        $activeServiceMobileNavigation = ($activeServiceMobileItem['navigate'] ?? true) ? 'navigate' : 'location';
        $activeServiceMobileValue = $activeServiceMobileNavigation.'|'.$activeServiceMobileGroup.'|'.route($activeServiceMobileItem['route'], $activeServiceMobileItem['parameters'] ?? $parameters);
        $serviceLinks = collect([]);
        $service->applications()->get()->each(function ($application) use ($serviceLinks) {
            $type = $application->serviceType();
            if ($type) {
                generateServiceSpecificFqdns($application)
                    ->map(fn ($link) => getFqdnWithoutPort($link))
                    ->each(fn ($link) => $serviceLinks->push($link));
            } else {
                if ($application->fqdn) {
                    collect(str($application->fqdn)->explode(','))
                        ->each(fn ($fqdn) => $serviceLinks->push(getFqdnWithoutPort($fqdn)));
                }
                if ($application->ports) {
                    collect(str($application->ports)->explode(','))
                        ->each(function ($port) use ($serviceLinks) {
                            $hostPort = str($port)->contains(':') ? str($port)->before(':') : $port;
                            $serviceLinks->push(base_url(withPort: false).":{$hostPort}");
                        });
                }
            }
        });
        $serviceMobileMenuChangeHandler = <<<'JS'
            const value = $event.target.value;

            if (!value) {
                return;
            }

            if (value.startsWith('navigate|')) {
                const url = value.split('|').slice(2).join('|');
                window.Livewire?.navigate ? window.Livewire.navigate(url) : window.location.href = url;
                return;
            }

            if (value.startsWith('location|')) {
                const url = value.split('|').slice(2).join('|');
                window.location.href = url;
                return;
            }

            resetToCurrent();

            if (value.startsWith('external:')) {
                window.open(value.slice(9), '_blank', 'noopener');
                return;
            }

            const action = value.slice(7);

            document.getElementById(`service-${action}-trigger`)?.click();
        JS;
    @endphp
    <livewire:project.shared.configuration-checker :resource="$service" />
    <x-slide-over @startservice.window="slideOverOpen = true" closeWithX fullScreen>
        <x-slot:title>Service Startup</x-slot:title>
        <x-slot:content>
            <livewire:activity-monitor header="Logs" fullHeight />
        </x-slot:content>
    </x-slide-over>
    <h1>{{ $title }}</h1>
    <x-resources.breadcrumbs :resource="$service" :parameters="$parameters" />
    <div class="navbar-main" x-data">
        <div class="mb-4 w-full md:mb-0 md:hidden">
            <label for="service-mobile-section" class="sr-only">Service menu</label>
            <select id="service-mobile-section" class="select w-full" aria-label="Service menu"
                data-current-value="{{ $activeServiceMobileValue }}"
                x-data="{
                    init() {
                        this.syncFromLocation();
                        window.Livewire?.hook?.('morphed', ({ el }) => {
                            if (el.contains(this.$el)) {
                                queueMicrotask(() => this.syncFromLocation());
                            }
                        });
                    },
                    selected: $el.dataset.currentValue,
                    current: $el.dataset.currentValue,
                    syncFromLocation() {
                        const currentUrl = new URL(window.location.href);
                        const matchingOptions = Array.from(this.$el.options).filter((option) => {
                            if (!option.value.startsWith('navigate|') && !option.value.startsWith('location|')) {
                                return false;
                            }

                            const optionUrl = new URL(option.value.split('|').slice(2).join('|'), window.location.origin);

                            return optionUrl.pathname === currentUrl.pathname;
                        });
                        const selectedOption = matchingOptions.find((option) => {
                            return option.value.startsWith('navigate|configuration|') || option.value.startsWith('navigate|resource|');
                        }) || matchingOptions[0];

                        if (selectedOption) {
                            this.current = selectedOption.value;
                            this.selected = selectedOption.value;
                        }
                    },
                    resetToCurrent() {
                        this.selected = this.current;
                    },
                }"
                x-on:livewire:navigated.window="syncFromLocation()"
                x-model="selected"
                x-on:change="{{ $serviceMobileMenuChangeHandler }}">
                <optgroup label="Service">
                    @foreach ($servicePageItems as $menuItem)
                        <option value="{{ ($menuItem['navigate'] ?? true) ? 'navigate' : 'location' }}|service|{{ route($menuItem['route'], $parameters) }}">
                            {{ $menuItem['label'] }}
                        </option>
                    @endforeach
                </optgroup>
                <optgroup label="Configuration">
                    @foreach ($serviceConfigurationItems as $menuItem)
                        <option value="navigate|configuration|{{ route($menuItem['route'], $parameters) }}">
                            {{ $menuItem['label'] }}
                        </option>
                    @endforeach
                </optgroup>
                @if (count($serviceResourceItems) > 0)
                    <optgroup label="Resource">
                        @foreach ($serviceResourceItems as $menuItem)
                            <option value="navigate|resource|{{ route($menuItem['route'], $menuItem['parameters'] ?? $parameters) }}">
                                {{ $menuItem['label'] }}
                            </option>
                        @endforeach
                    </optgroup>
                @endif
                <optgroup label="Links">
                    @if (filled($service->documentation()))
                        <option value="external:{{ $service->documentation() }}">Documentation</option>
                    @endif
                    @forelse ($serviceLinks as $link)
                        <option value="external:{{ $link }}">{{ $link }}</option>
                    @empty
                        @if (blank($service->documentation()))
                            <option disabled>No links available</option>
                        @endif
                    @endforelse
                </optgroup>
                @if ($service->isDeployable)
                    <optgroup label="Actions">
                        @if (str($service->status)->contains('running'))
                            <option value="action:restart">Restart</option>
                            <option value="action:stop">Stop</option>
                            <option value="action:pullAndRestart">Pull Latest Images & Restart</option>
                        @elseif (str($service->status)->contains('degraded'))
                            <option value="action:restart">Restart</option>
                            <option value="action:stop">Stop</option>
                            <option value="action:forceDeploy">Force Restart</option>
                        @elseif (str($service->status)->contains('exited'))
                            <option value="action:start">Deploy</option>
                            <option value="action:forceDeploy">Force Deploy</option>
                            <option value="action:cleanup">Force Cleanup Containers</option>
                        @else
                            <option value="action:stop">Stop</option>
                            <option value="action:start">Deploy</option>
                            <option value="action:forceDeploy">Force Deploy</option>
                            <option value="action:cleanup">Force Cleanup Containers</option>
                        @endif
                    </optgroup>
                @endif
            </select>
        </div>
        <nav
            class="scrollbar hidden min-h-10 w-full flex-nowrap items-center gap-6 overflow-x-scroll overflow-y-hidden pb-1 whitespace-nowrap md:flex md:w-auto md:overflow-visible">
            <a class="shrink-0 {{ request()->routeIs('project.service.configuration') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('project.service.configuration', $parameters) }}">
                <button>Configuration</button>
            </a>
            <a class="shrink-0 {{ request()->routeIs('project.service.logs') ? 'dark:text-white' : '' }}"
                href="{{ route('project.service.logs', $parameters) }}">
                <button>Logs</button>
            </a>
            @can('canAccessTerminal')
                <a class="shrink-0 {{ request()->routeIs('project.service.command') ? 'dark:text-white' : '' }}"
                    href="{{ route('project.service.command', $parameters) }}">
                    <button>Terminal</button>
                </a>
            @endcan
            <div class="shrink-0">
                <x-services.links :service="$service" />
            </div>
        </nav>

        @if ($service->isDeployable)
            <div class="hidden flex-wrap items-center gap-2 md:flex">
                <div class="flex flex-wrap items-center gap-2">
                    <x-services.advanced :service="$service" />
                    @if (str($service->status)->contains('running'))
                        <x-forms.button title="Restart" @click="document.getElementById('service-restart-trigger')?.click()">
                            <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="2">
                                    <path d="M19.933 13.041 a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                                    <path d="M20 4v5h-5" />
                                </g>
                            </svg>
                            Restart
                        </x-forms.button>
                        <x-forms.button isError title="Stop" @click="document.getElementById('service-stop-trigger')?.click()">
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
                        </x-forms.button>
                    @elseif (str($service->status)->contains('degraded'))
                        <x-forms.button title="Restart" @click="document.getElementById('service-restart-trigger')?.click()">
                            <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="2">
                                    <path d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                                    <path d="M20 4v5h-5" />
                                </g>
                            </svg>
                            Restart
                        </x-forms.button>
                        <x-forms.button isError title="Stop" @click="document.getElementById('service-stop-trigger')?.click()">
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
                        </x-forms.button>
                    @elseif (str($service->status)->contains('exited'))
                        <button @click="document.getElementById('service-start-trigger')?.click()" class="gap-2 button">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M7 4v16l13 -8z" />
                            </svg>
                            Deploy
                        </button>
                    @else
                        <x-forms.button isError title="Stop" @click="document.getElementById('service-stop-trigger')?.click()">
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
                        </x-forms.button>
                        <button @click="document.getElementById('service-start-trigger')?.click()" class="gap-2 button">
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
        @else
            <div class="flex flex-wrap order-first gap-2 items-center sm:order-last">
                <div class="text-error">
                    Unable to deploy. <a class="underline font-bold cursor-pointer" {{ wireNavigate() }}
                        href="{{ route('project.service.environment-variables', $parameters) }}">
                        Required environment variables missing.</a>
                </div>
            </div>
        @endif
    </div>
    @if ($service->isDeployable)
            <x-modal-confirmation title="Confirm Service Deployment?" buttonTitle="Deploy"
                submitAction="startEvent" :dispatchAction="true" :actions="['This service will be deployed.']"
                :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Confirm">
                <x-slot:trigger>
                    <button id="service-start-trigger" type="button" class="hidden">Deploy</button>
                </x-slot:trigger>
            </x-modal-confirmation>
            <x-modal-confirmation title="Confirm Service Restart?" buttonTitle="Restart"
                submitAction="restartEvent" :dispatchAction="true" :actions="['This service will be restarted.']"
                :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Confirm">
                <x-slot:trigger>
                    <button id="service-restart-trigger" type="button" class="hidden">Restart</button>
                </x-slot:trigger>
            </x-modal-confirmation>
            <x-modal-confirmation title="Confirm Service Stopping?" buttonTitle="Stop"
                submitAction="stop" :checkboxes="$checkboxes" :actions="[__('service.stop'), __('resource.non_persistent')]"
                :confirmWithText="false" :confirmWithPassword="false" step1ButtonText="Continue" step2ButtonText="Confirm">
                <x-slot:trigger>
                    <button id="service-stop-trigger" type="button" class="hidden">Stop</button>
                </x-slot:trigger>
            </x-modal-confirmation>
            <x-modal-confirmation title="Confirm Service Force Deployment?" buttonTitle="Force Deploy"
                submitAction="forceDeployEvent" :dispatchAction="true" :actions="['This service will be force deployed.']"
                :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Confirm">
                <x-slot:trigger>
                    <button id="service-forceDeploy-trigger" type="button" class="hidden">Force Deploy</button>
                </x-slot:trigger>
            </x-modal-confirmation>
            <x-modal-confirmation title="Confirm Pull Latest Images & Restart?" buttonTitle="Pull Latest Images & Restart"
                submitAction="pullAndRestartEvent" :dispatchAction="true" :actions="['Latest images will be pulled and the service will be restarted.']"
                :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Confirm">
                <x-slot:trigger>
                    <button id="service-pullAndRestart-trigger" type="button" class="hidden">Pull Latest Images & Restart</button>
                </x-slot:trigger>
            </x-modal-confirmation>
            <x-modal-confirmation title="Confirm Force Cleanup Containers?" buttonTitle="Force Cleanup Containers"
                submitAction="cleanupEvent" :dispatchAction="true" :actions="['Service containers will be force cleaned up.']"
                :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Confirm">
                <x-slot:trigger>
                    <button id="service-cleanup-trigger" type="button" class="hidden">Force Cleanup Containers</button>
                </x-slot:trigger>
            </x-modal-confirmation>
    @endif

    @script
        <script>
            $wire.$on('stopEvent', () => {
                $wire.$dispatch('info',
                    'Gracefully stopping service.<br/><br/>It could take a while depending on the service.');
                $wire.$call('stop');
            });
            $wire.$on('startEvent', async () => {
                const isDeploymentProgress = await $wire.$call('checkDeployments');
                if (isDeploymentProgress) {
                    $wire.$dispatch('error',
                        'There is a deployment in progress.<br><br>You can force deploy in the "Advanced" section.'
                    );
                    return;
                }
                window.dispatchEvent(new CustomEvent('startservice'));
                $wire.$call('start');
            });
            $wire.$on('forceDeployEvent', () => {
                window.dispatchEvent(new CustomEvent('startservice'));
                $wire.$call('forceDeploy');
            });
            $wire.$on('restartEvent', async () => {
                const isDeploymentProgress = await $wire.$call('checkDeployments');
                if (isDeploymentProgress) {
                    $wire.$dispatch('error',
                        'There is a deployment in progress.<br><br>You can force deploy in the "Advanced" section.'
                    );
                    return;
                }
                $wire.$dispatch('info',
                    'Gracefully stopping service.<br/><br/>It could take a while depending on the service.');
                window.dispatchEvent(new CustomEvent('startservice'));
                $wire.$call('restart');
            });
            $wire.$on('pullAndRestartEvent', () => {
                $wire.$dispatch('info', 'Pulling new images and restarting service.');
                window.dispatchEvent(new CustomEvent('startservice'));
                $wire.$call('pullAndRestartEvent');
            });
            $wire.$on('cleanupEvent', () => {
                $wire.$call('stop', true);
            });
            $wire.on('imagePulled', () => {
                window.dispatchEvent(new CustomEvent('startservice'));
                $wire.$dispatch('info', 'Restarting service.');
            });
        </script>
    @endscript
</div>
