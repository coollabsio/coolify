<nav wire:poll.10000ms="checkStatus" class="pb-6">
    @php
        $applicationMenuItems = [
            [
                'label' => 'Configuration',
                'route' => 'project.application.configuration',
                'active' => request()->routeIs('project.application.configuration'),
            ],
            [
                'label' => 'Deployments',
                'route' => 'project.application.deployment.index',
                'active' => request()->routeIs('project.application.deployment.index', 'project.application.deployment.show'),
            ],
            [
                'label' => 'Logs',
                'route' => 'project.application.logs',
                'active' => request()->routeIs('project.application.logs'),
            ],
            [
                'label' => 'Terminal',
                'route' => 'project.application.command',
                'active' => request()->routeIs('project.application.command'),
                'navigate' => false,
                'visible' => ! $application->destination->server->isSwarm() && auth()->user()?->can('canAccessTerminal'),
            ],
        ];

        $configurationMenuItems = [
            [
                'label' => 'General',
                'route' => 'project.application.configuration',
                'active' => request()->routeIs('project.application.configuration'),
            ],
            [
                'label' => 'Advanced',
                'route' => 'project.application.advanced',
                'active' => request()->routeIs('project.application.advanced'),
            ],
            [
                'label' => 'Swarm',
                'route' => 'project.application.swarm',
                'active' => request()->routeIs('project.application.swarm'),
                'visible' => $application->destination->server->isSwarm(),
            ],
            [
                'label' => 'Environment Variables',
                'route' => 'project.application.environment-variables',
                'active' => request()->routeIs('project.application.environment-variables'),
            ],
            [
                'label' => 'Persistent Storage',
                'route' => 'project.application.persistent-storage',
                'active' => request()->routeIs('project.application.persistent-storage'),
            ],
            [
                'label' => 'Git Source',
                'route' => 'project.application.source',
                'active' => request()->routeIs('project.application.source'),
                'visible' => $application->git_based(),
            ],
            [
                'label' => 'Servers',
                'route' => 'project.application.servers',
                'active' => request()->routeIs('project.application.servers'),
            ],
            [
                'label' => 'Scheduled Tasks',
                'route' => 'project.application.scheduled-tasks.show',
                'active' => request()->routeIs('project.application.scheduled-tasks.show', 'project.application.scheduled-tasks'),
            ],
            [
                'label' => 'Webhooks',
                'route' => 'project.application.webhooks',
                'active' => request()->routeIs('project.application.webhooks'),
            ],
            [
                'label' => 'Preview Deployments',
                'route' => 'project.application.preview-deployments',
                'active' => request()->routeIs('project.application.preview-deployments'),
                'visible' => $application->git_based() || $application->build_pack === 'dockerimage',
            ],
            [
                'label' => 'Healthcheck',
                'route' => 'project.application.healthcheck',
                'active' => request()->routeIs('project.application.healthcheck'),
                'visible' => $application->build_pack !== 'dockercompose',
            ],
            [
                'label' => 'Rollback',
                'route' => 'project.application.rollback',
                'active' => request()->routeIs('project.application.rollback'),
            ],
            [
                'label' => 'Resource Limits',
                'route' => 'project.application.resource-limits',
                'active' => request()->routeIs('project.application.resource-limits'),
            ],
            [
                'label' => 'Resource Operations',
                'route' => 'project.application.resource-operations',
                'active' => request()->routeIs('project.application.resource-operations'),
            ],
            [
                'label' => 'Metrics',
                'route' => 'project.application.metrics',
                'active' => request()->routeIs('project.application.metrics'),
            ],
            [
                'label' => 'Tags',
                'route' => 'project.application.tags',
                'active' => request()->routeIs('project.application.tags'),
            ],
            [
                'label' => 'Danger Zone',
                'route' => 'project.application.danger',
                'active' => request()->routeIs('project.application.danger'),
            ],
        ];

        $applicationMenuItems = array_values(array_filter(
            $applicationMenuItems,
            fn (array $item): bool => $item['visible'] ?? true,
        ));
        $configurationMenuItems = array_values(array_filter(
            $configurationMenuItems,
            fn (array $item): bool => $item['visible'] ?? true,
        ));
        $activeConfigurationMenuItem = collect($configurationMenuItems)->firstWhere('active', true);
        $activeApplicationMenuItem = collect($applicationMenuItems)->firstWhere('active', true);
        $activeMobileMenuItem = $activeConfigurationMenuItem
            ?? $activeApplicationMenuItem
            ?? $applicationMenuItems[0];
        $activeMobileMenuGroup = $activeConfigurationMenuItem ? 'configuration' : 'application';
        $activeMobileNavigation = ($activeMobileMenuItem['navigate'] ?? true) ? 'navigate' : 'location';
        $activeMobileMenuValue = $activeMobileNavigation.'|'.$activeMobileMenuGroup.'|'.route($activeMobileMenuItem['route'], $parameters);
        $mobileSectionChangeHandler = <<<'JS'
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
            document.getElementById(`application-mobile-${action}-trigger`)?.click();
        JS;
    @endphp
    <x-resources.breadcrumbs :resource="$application" :parameters="$parameters" :title="$lastDeploymentInfo" :lastDeploymentLink="$lastDeploymentLink" />
    <div class="navbar-main">
        <div class="w-full md:hidden">
            <label for="application-mobile-section" class="sr-only">Application menu</label>
            <select id="application-mobile-section" class="select w-full" aria-label="Application menu"
                data-current-value="{{ $activeMobileMenuValue }}"
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
                x-on:change="{{ $mobileSectionChangeHandler }}">
                <optgroup label="Application">
                    @foreach ($applicationMenuItems as $menuItem)
                        <option value="{{ ($menuItem['navigate'] ?? true) ? 'navigate' : 'location' }}|application|{{ route($menuItem['route'], $parameters) }}">
                            {{ $menuItem['label'] }}
                        </option>
                    @endforeach
                </optgroup>
                <optgroup label="Configuration">
                    @foreach ($configurationMenuItems as $menuItem)
                        <option value="navigate|configuration|{{ route($menuItem['route'], $parameters) }}">
                            {{ $menuItem['label'] }}
                        </option>
                    @endforeach
                </optgroup>
                <optgroup label="Links">
                    @if (
                        (data_get($application, 'fqdn') ||
                            collect(json_decode($application->docker_compose_domains))->contains(fn($fqdn) => !empty(data_get($fqdn, 'domain'))) ||
                            data_get($application, 'previews', collect([]))->count() > 0 ||
                            data_get($application, 'ports_mappings_array')) &&
                            data_get($application, 'settings.is_raw_compose_deployment_enabled') !== true)
                        @if (data_get($application, 'gitBrancLocation'))
                            <option value="external:{{ $application->gitBranchLocation }}">Git Repository</option>
                        @endif
                        @if (data_get($application, 'build_pack') === 'dockercompose')
                            @foreach (collect(json_decode($application->docker_compose_domains)) as $fqdn)
                                @if (data_get($fqdn, 'domain'))
                                    @foreach (explode(',', data_get($fqdn, 'domain')) as $domain)
                                        <option value="external:{{ getFqdnWithoutPort($domain) }}">{{ getFqdnWithoutPort($domain) }}</option>
                                    @endforeach
                                @endif
                            @endforeach
                        @endif
                        @if (data_get($application, 'fqdn'))
                            @foreach (str(data_get($application, 'fqdn'))->explode(',') as $fqdn)
                                <option value="external:{{ getFqdnWithoutPort($fqdn) }}">{{ getFqdnWithoutPort($fqdn) }}</option>
                            @endforeach
                        @endif
                        @if (data_get($application, 'previews', collect())->count() > 0)
                            @if (data_get($application, 'build_pack') === 'dockercompose')
                                @foreach ($application->previews as $preview)
                                    @foreach (collect(json_decode($preview->docker_compose_domains)) as $fqdn)
                                        @if (data_get($fqdn, 'domain'))
                                            @foreach (explode(',', data_get($fqdn, 'domain')) as $domain)
                                                <option value="external:{{ getFqdnWithoutPort($domain) }}">PR{{ data_get($preview, 'pull_request_id') }} | {{ getFqdnWithoutPort($domain) }}</option>
                                            @endforeach
                                        @endif
                                    @endforeach
                                @endforeach
                            @else
                                @foreach (data_get($application, 'previews') as $preview)
                                    @if (data_get($preview, 'fqdn'))
                                        <option value="external:{{ getFqdnWithoutPort(data_get($preview, 'fqdn')) }}">PR{{ data_get($preview, 'pull_request_id') }} | {{ data_get($preview, 'fqdn') }}</option>
                                    @endif
                                @endforeach
                            @endif
                        @endif
                        @if (data_get($application, 'ports_mappings_array'))
                            @foreach ($application->ports_mappings_array as $port)
                                @if ($application->destination->server->id === 0)
                                    <option value="external:http://localhost:{{ explode(':', $port)[0] }}">Port {{ $port }}</option>
                                @else
                                    <option value="external:http://{{ $application->destination->server->ip }}:{{ explode(':', $port)[0] }}">{{ $application->destination->server->ip }}:{{ explode(':', $port)[0] }}</option>
                                    @if (count($application->additional_servers) > 0)
                                        @foreach ($application->additional_servers as $server)
                                            <option value="external:http://{{ $server->ip }}:{{ explode(':', $port)[0] }}">{{ $server->ip }}:{{ explode(':', $port)[0] }}</option>
                                        @endforeach
                                    @endif
                                @endif
                            @endforeach
                        @endif
                    @else
                        <option disabled>No links available</option>
                    @endif
                </optgroup>
            </select>
            @if (!($application->build_pack === 'dockercompose' && is_null($application->docker_compose_raw)))
                <div id="application-mobile-actions" class="mt-2 flex flex-nowrap items-center gap-2 overflow-x-auto md:hidden">
                    @if (!str($application->status)->startsWith('exited'))
                        @if (!$application->destination->server->isSwarm())
                            <button type="button" class="button shrink-0"
                                @click="document.getElementById('application-mobile-deploy-trigger')?.click()">
                                Redeploy
                            </button>
                        @endif
                        @if ($application->build_pack !== 'dockercompose')
                            @if ($application->destination->server->isSwarm())
                                <button type="button" class="button shrink-0"
                                    @click="document.getElementById('application-mobile-deploy-trigger')?.click()">
                                    Update Service
                                </button>
                            @else
                                <button type="button" class="button shrink-0"
                                    @click="document.getElementById('application-mobile-restart-trigger')?.click()">
                                    Restart
                                </button>
                            @endif
                        @endif
                        <button type="button" class="button shrink-0 text-error"
                            @click="document.getElementById('application-mobile-stop-trigger')?.click()">
                            Stop
                        </button>
                    @else
                        <button type="button" class="button shrink-0"
                            @click="document.getElementById('application-mobile-deploy-trigger')?.click()">
                            Deploy
                        </button>
                    @endif
                    @if (!$application->destination->server->isSwarm())
                        @if ($application->status === 'running')
                            <button type="button" class="button shrink-0"
                                @click="document.getElementById('application-mobile-force-deploy-trigger')?.click()">
                                Force deploy (without cache)
                            </button>
                        @else
                            <button type="button" class="button shrink-0"
                                @click="document.getElementById('application-mobile-deploy-force-trigger')?.click()">
                                Force deploy (without cache)
                            </button>
                        @endif
                    @endif
                </div>
            @endif
            <x-modal-confirmation title="Confirm Application Stopping?" buttonTitle="Stop"
                submitAction="stop" :checkboxes="$checkboxes" :actions="[
                    'This application will be stopped.',
                    'All non-persistent data of this application will be deleted.',
                ]" :confirmWithText="false" :confirmWithPassword="false"
                step1ButtonText="Continue" step2ButtonText="Confirm">
                <x-slot:trigger>
                    <button id="application-mobile-stop-trigger" type="button" class="hidden">Stop</button>
                </x-slot:trigger>
            </x-modal-confirmation>
            <x-modal-confirmation title="Confirm Application Deployment?" buttonTitle="Deploy"
                submitAction="deploy" :actions="[
                    'This application will be deployed.',
                ]" :confirmWithText="false" :confirmWithPassword="false"
                step2ButtonText="Confirm">
                <x-slot:trigger>
                    <button id="application-mobile-deploy-trigger" type="button" class="hidden">Deploy</button>
                </x-slot:trigger>
            </x-modal-confirmation>
            <x-modal-confirmation title="Confirm Application Restart?" buttonTitle="Restart"
                submitAction="restart" :actions="[
                    'This application will be restarted without rebuilding.',
                ]" :confirmWithText="false" :confirmWithPassword="false"
                step2ButtonText="Confirm">
                <x-slot:trigger>
                    <button id="application-mobile-restart-trigger" type="button" class="hidden">Restart</button>
                </x-slot:trigger>
            </x-modal-confirmation>
            <x-modal-confirmation title="Confirm Application Force Deployment?" buttonTitle="Force deploy"
                submitAction="force_deploy_without_cache" :actions="[
                    'This application will be force deployed without build cache.',
                ]" :confirmWithText="false" :confirmWithPassword="false"
                step2ButtonText="Confirm">
                <x-slot:trigger>
                    <button id="application-mobile-force-deploy-trigger" type="button" class="hidden">Force deploy</button>
                </x-slot:trigger>
            </x-modal-confirmation>
            <x-modal-confirmation title="Confirm Application Force Deployment?" buttonTitle="Force deploy"
                submitAction="deploy(true)" :actions="[
                    'This application will be force deployed without build cache.',
                ]" :confirmWithText="false" :confirmWithPassword="false"
                step2ButtonText="Confirm">
                <x-slot:trigger>
                    <button id="application-mobile-deploy-force-trigger" type="button" class="hidden">Force deploy</button>
                </x-slot:trigger>
            </x-modal-confirmation>
        </div>

        <nav
            class="scrollbar hidden min-h-10 w-full flex-nowrap items-center gap-6 overflow-x-scroll overflow-y-hidden pb-1 whitespace-nowrap md:flex md:w-auto md:overflow-visible">
            <a class="hidden md:block shrink-0 {{ request()->routeIs('project.application.configuration') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('project.application.configuration', $parameters) }}">
                Configuration
            </a>
            <a class="hidden md:block shrink-0 {{ request()->routeIs('project.application.deployment.index') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('project.application.deployment.index', $parameters) }}">
                Deployments
            </a>
            <a class="hidden md:block shrink-0 {{ request()->routeIs('project.application.logs') ? 'dark:text-white' : '' }}"
                href="{{ route('project.application.logs', $parameters) }}">
                <div class="flex items-center gap-1">
                    Logs
                    @if ($application->restart_count > 0 && (!str($application->status)->startsWith('exited') || $application->stoppedAfterRestartLimit()))
                        <svg class="w-4 h-4 dark:text-warning" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" title="Container has restarted {{ $application->restart_count }} time{{ $application->restart_count > 1 ? 's' : '' }}">
                            <path d="M12 2L1 21h22L12 2zm0 4l7.53 13H4.47L12 6zm-1 5v4h2v-4h-2zm0 5v2h2v-2h-2z"/>
                        </svg>
                    @endif
                </div>
            </a>
            @if (!$application->destination->server->isSwarm())
                @can('canAccessTerminal')
                    <a class="hidden md:block shrink-0 {{ request()->routeIs('project.application.command') ? 'dark:text-white' : '' }}"
                        href="{{ route('project.application.command', $parameters) }}">
                        Terminal
                    </a>
                @endcan
            @endif
            <div class="hidden shrink-0 md:block">
                <x-applications.links :application="$application" />
            </div>
        </nav>
        <div class="flex flex-wrap gap-2 items-center">
            @if ($application->build_pack === 'dockercompose' && is_null($application->docker_compose_raw))
                <div>Please load a Compose file.</div>
            @else
                <div class="hidden flex-wrap items-center gap-2 md:flex">
                    @if (!$application->destination->server->isSwarm())
                        <div>
                            <x-applications.advanced :application="$application" />
                        </div>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        @if (!str($application->status)->startsWith('exited'))
                            @if (!$application->destination->server->isSwarm())
                                <x-modal-confirmation title="Confirm Application Deployment?" buttonTitle="Redeploy"
                                    submitAction="deploy" :actions="[
                                        'This application will be redeployed.',
                                    ]" :confirmWithText="false" :confirmWithPassword="false"
                                    step2ButtonText="Confirm">
                                    <x-slot:content>
                                        <x-forms.button title="With rolling update if possible">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-orange-400"
                                                viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"
                                                stroke-linecap="round" stroke-linejoin="round">
                                                <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                                <path
                                                    d="M10.09 4.01l.496 -.495a2 2 0 0 1 2.828 0l7.071 7.07a2 2 0 0 1 0 2.83l-7.07 7.07a2 2 0 0 1 -2.83 0l-7.07 -7.07a2 2 0 0 1 0 -2.83l3.535 -3.535h-3.988">
                                                </path>
                                                <path d="M7.05 11.038v-3.988"></path>
                                            </svg>
                                            Redeploy
                                        </x-forms.button>
                                    </x-slot:content>
                                </x-modal-confirmation>
                            @endif
                            @if ($application->build_pack !== 'dockercompose')
                                @if ($application->destination->server->isSwarm())
                                    <x-modal-confirmation title="Confirm Application Deployment?" buttonTitle="Update Service"
                                        submitAction="deploy" :actions="[
                                            'This Swarm service will be updated with a rolling deployment.',
                                        ]" :confirmWithText="false" :confirmWithPassword="false"
                                        step2ButtonText="Confirm">
                                        <x-slot:content>
                                            <x-forms.button title="Redeploy Swarm Service (rolling update)">
                                                <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                                                    xmlns="http://www.w3.org/2000/svg">
                                                    <g fill="none" stroke="currentColor" stroke-linecap="round"
                                                        stroke-linejoin="round" stroke-width="2">
                                                        <path
                                                            d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                                                        <path d="M20 4v5h-5" />
                                                    </g>
                                                </svg>
                                                Update Service
                                            </x-forms.button>
                                        </x-slot:content>
                                    </x-modal-confirmation>
                                @else
                                    <x-modal-confirmation title="Confirm Application Restart?" buttonTitle="Restart"
                                        submitAction="restart" :actions="[
                                            'This application will be restarted without rebuilding.',
                                        ]" :confirmWithText="false" :confirmWithPassword="false"
                                        step2ButtonText="Confirm">
                                        <x-slot:content>
                                            <x-forms.button title="Restart without rebuilding">
                                                <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                                                    xmlns="http://www.w3.org/2000/svg">
                                                    <g fill="none" stroke="currentColor" stroke-linecap="round"
                                                        stroke-linejoin="round" stroke-width="2">
                                                        <path
                                                            d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                                                        <path d="M20 4v5h-5" />
                                                    </g>
                                                </svg>
                                                Restart
                                            </x-forms.button>
                                        </x-slot:content>
                                    </x-modal-confirmation>
                                @endif
                            @endif
                            <x-modal-confirmation title="Confirm Application Stopping?" buttonTitle="Stop"
                                submitAction="stop" :checkboxes="$checkboxes" :actions="[
                                    'This application will be stopped.',
                                    'All non-persistent data of this application will be deleted.',
                                ]" :confirmWithText="false" :confirmWithPassword="false"
                                step1ButtonText="Continue" step2ButtonText="Confirm">
                                <x-slot:button-title>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-error" viewBox="0 0 24 24"
                                        stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                        <path
                                            d="M6 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                                        </path>
                                        <path
                                            d="M14 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                                        </path>
                                    </svg>
                                    Stop
                                </x-slot:button-title>
                            </x-modal-confirmation>
                        @else
                            <x-modal-confirmation title="Confirm Application Deployment?" buttonTitle="Deploy"
                                submitAction="deploy" :actions="[
                                    'This application will be deployed.',
                                ]" :confirmWithText="false" :confirmWithPassword="false"
                                step2ButtonText="Confirm">
                                <x-slot:content>
                                    <x-forms.button>
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning"
                                            viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                            <path d="M7 4v16l13 -8z" />
                                        </svg>
                                        Deploy
                                    </x-forms.button>
                                </x-slot:content>
                            </x-modal-confirmation>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</nav>
