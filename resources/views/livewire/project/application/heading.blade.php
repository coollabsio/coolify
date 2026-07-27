<nav wire:poll.10000ms="checkStatus" class="w-full max-w-[1180px] pb-6 lg:pb-0">
    @php
        $routeIs = fn (string|array $routes): bool => \Illuminate\Support\Str::is($routes, $activeRouteName);
        $applicationMenuItems = [
            [
                'label' => 'Settings',
                'route' => 'project.application.configuration',
                'active' => $routeIs('project.application.configuration'),
            ],
            [
                'label' => 'Backups',
                'route' => 'project.application.backup.index',
                'active' => $routeIs('project.application.backup.*'),
            ],
            [
                'label' => 'Console',
                'route' => 'project.application.command',
                'active' => $routeIs('project.application.command'),
                'navigate' => false,
                'visible' => ! $application->destination->server->isSwarm() && auth()->user()?->can('canAccessTerminal'),
            ],
            [
                'label' => 'Deployment Logs',
                'route' => 'project.application.deployment.index',
                'active' => $routeIs(['project.application.deployment.index', 'project.application.deployment.show']),
            ],
            [
                'label' => 'Runtime Logs',
                'route' => 'project.application.logs',
                'active' => $routeIs('project.application.logs'),
            ],
        ];

        $configurationMenuItems = [
            [
                'label' => 'General',
                'route' => 'project.application.configuration',
                'active' => $routeIs('project.application.configuration'),
            ],
            [
                'label' => 'Advanced',
                'route' => 'project.application.advanced',
                'active' => $routeIs('project.application.advanced'),
            ],
            [
                'label' => 'Swarm',
                'route' => 'project.application.swarm',
                'active' => $routeIs('project.application.swarm'),
                'visible' => $application->destination->server->isSwarm(),
            ],
            [
                'label' => 'Environment Variables',
                'route' => 'project.application.environment-variables',
                'active' => $routeIs('project.application.environment-variables'),
            ],
            [
                'label' => 'Persistent Storage',
                'route' => 'project.application.persistent-storage',
                'active' => $routeIs('project.application.persistent-storage'),
            ],
            [
                'label' => 'Git Source',
                'route' => 'project.application.source',
                'active' => $routeIs('project.application.source'),
                'visible' => $application->git_based(),
            ],
            [
                'label' => 'Servers',
                'route' => 'project.application.servers',
                'active' => $routeIs('project.application.servers'),
            ],
            [
                'label' => 'Scheduled Tasks',
                'route' => 'project.application.scheduled-tasks.show',
                'active' => $routeIs(['project.application.scheduled-tasks.show', 'project.application.scheduled-tasks']),
            ],
            [
                'label' => 'Webhooks',
                'route' => 'project.application.webhooks',
                'active' => $routeIs('project.application.webhooks'),
            ],
            [
                'label' => 'Preview Deployments',
                'route' => 'project.application.preview-deployments',
                'active' => $routeIs('project.application.preview-deployments'),
                'visible' => $application->git_based() || $application->build_pack === 'dockerimage',
            ],
            [
                'label' => 'Healthcheck',
                'route' => 'project.application.healthcheck',
                'active' => $routeIs('project.application.healthcheck'),
                'visible' => $application->build_pack !== 'dockercompose',
            ],
            [
                'label' => 'Rollback',
                'route' => 'project.application.rollback',
                'active' => $routeIs('project.application.rollback'),
            ],
            [
                'label' => 'Resource Limits',
                'route' => 'project.application.resource-limits',
                'active' => $routeIs('project.application.resource-limits'),
            ],
            [
                'label' => 'Resource Operations',
                'route' => 'project.application.resource-operations',
                'active' => $routeIs('project.application.resource-operations'),
            ],
            [
                'label' => 'Metrics',
                'route' => 'project.application.metrics',
                'active' => $routeIs('project.application.metrics'),
            ],
            [
                'label' => 'Tags',
                'route' => 'project.application.tags',
                'active' => $routeIs('project.application.tags'),
            ],
            [
                'label' => 'Danger Zone',
                'route' => 'project.application.danger',
                'active' => $routeIs('project.application.danger'),
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
    <div>
        <div class="w-full md:hidden">
            @if (!($application->build_pack === 'dockercompose' && is_null($application->docker_compose_raw)))
                <div id="application-mobile-actions" class="mt-2 mb-3 md:hidden">
                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Actions</div>
                    <div class="flex flex-nowrap items-center gap-2 overflow-x-auto">
                    @if (!str($application->status)->startsWith('exited'))
                        @if (!$application->destination->server->isSwarm())
                            <button type="button" class="button shrink-0" wire:click="deploy">
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
                            </button>
                        @endif
                        @if ($application->build_pack !== 'dockercompose')
                            @if ($application->destination->server->isSwarm())
                                <button type="button" class="button shrink-0" wire:click="deploy">
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
                                </button>
                            @else
                                <button type="button" class="button shrink-0"
                                    @click="document.getElementById('application-mobile-restart-trigger')?.click()">
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
                                </button>
                            @endif
                        @endif
                        <x-forms.button isError class="shrink-0"
                            @click="document.getElementById('application-mobile-stop-trigger')?.click()">
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
                        </x-forms.button>
                    @else
                        <button type="button" class="button shrink-0" wire:click="deploy">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning"
                                viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M7 4v16l13 -8z" />
                            </svg>
                            Deploy
                        </button>
                    @endif
                    @if (!$application->destination->server->isSwarm())
                        @if ($application->status === 'running')
                            <button type="button" class="button shrink-0" wire:click="force_deploy_without_cache">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning"
                                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                    <path d="M7 4v16l13 -8z" />
                                </svg>
                                Force deploy (without cache)
                            </button>
                        @else
                            <button type="button" class="button shrink-0" wire:click="deploy(true)">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning"
                                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                    <path d="M7 4v16l13 -8z" />
                                </svg>
                                Force deploy (without cache)
                            </button>
                        @endif
                    @endif
                    </div>
                </div>
            @endif
            <div
                class="flex min-w-0 items-center gap-0.5 overflow-x-auto rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                @foreach ($applicationMenuItems as $menuItem)
                    @php
                        $isMobileApplicationItemActive = $menuItem['active']
                            || ($menuItem['label'] === 'Settings' && $activeConfigurationMenuItem);
                    @endphp
                    <a @class([
                        'app-tab shrink-0',
                        'bg-coollabs/10 text-coollabs ring-1 ring-coollabs/25 dark:bg-warning/15 dark:text-warning dark:ring-warning/25' => $isMobileApplicationItemActive,
                    ])
                        @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                        href="{{ route($menuItem['route'], $parameters) }}">
                        {{ $menuItem['label'] }}
                    </a>
                @endforeach
            </div>
            @if ($activeConfigurationMenuItem)
                <div
                    class="mt-2 flex min-w-0 items-center gap-0.5 overflow-x-auto rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                    @foreach ($configurationMenuItems as $menuItem)
                        <a @class([
                            'app-tab shrink-0',
                            'bg-coollabs/10 text-coollabs ring-1 ring-coollabs/25 dark:bg-warning/15 dark:text-warning dark:ring-warning/25' => $menuItem['active'],
                        ])
                            {{ wireNavigate() }} href="{{ route($menuItem['route'], $parameters) }}">
                            {{ $menuItem['label'] }}
                        </a>
                    @endforeach
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
            <x-modal-confirmation title="Confirm Application Restart?" buttonTitle="Restart"
                submitAction="restart" :actions="[
                    'This application will be restarted without rebuilding.',
                ]" :confirmWithText="false" :confirmWithPassword="false"
                step2ButtonText="Confirm">
                <x-slot:trigger>
                    <button id="application-mobile-restart-trigger" type="button" class="hidden">Restart</button>
                </x-slot:trigger>
            </x-modal-confirmation>
        </div>

        {{-- Layer-2 top nav: fixed under the topbar on desktop, in-flow on smaller screens --}}
        <div class="hidden w-full items-center justify-between gap-4 md:flex lg:fixed lg:top-12 lg:right-0 lg:z-30 lg:h-12 lg:w-auto lg:border-b lg:border-neutral-200 lg:bg-white/95 lg:pl-2 lg:pr-4 lg:backdrop-blur lg:transition-[left] lg:duration-200 lg:dark:border-white/[0.06] lg:dark:bg-panel/95"
            :class="[typeof collapsed !== 'undefined' && collapsed ? 'lg:left-16' : 'lg:left-56']">
            <div class="flex min-w-0 items-center gap-2">
                <div class="application-primary-tabs flex min-w-0 items-center gap-0.5 overflow-x-auto rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                @foreach ($applicationMenuItems as $menuItem)
                    @php
                        $isApplicationMenuItemActive = $menuItem['active']
                            || ($menuItem['label'] === 'Settings' && $activeConfigurationMenuItem);
                    @endphp
                    <a wire:key="application-primary-nav-{{ str($menuItem['label'])->slug() }}"
                        @class([
                            'app-tab shrink-0',
                            'bg-coollabs/10 text-coollabs shadow-sm ring-1 ring-coollabs/25 hover:bg-coollabs/15 dark:bg-warning/15 dark:text-warning dark:ring-warning/25 dark:hover:bg-warning/20' => $isApplicationMenuItemActive,
                        ])
                        @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                        href="{{ route($menuItem['route'], $parameters) }}">
                        {{ $menuItem['label'] }}
                        @if ($menuItem['label'] === 'Runtime Logs' && $application->restart_count > 0 && (!str($application->status)->startsWith('exited') || $application->stoppedAfterRestartLimit()))
                            <span class="size-1.5 rounded-full bg-warning"
                                title="Container has restarted {{ $application->restart_count }} time{{ $application->restart_count > 1 ? 's' : '' }}"></span>
                        @endif
                    </a>
                @endforeach
                </div>
                <div class="application-heading-actions flex shrink-0 items-center rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                    <x-applications.links :application="$application" />
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-3">
                {{-- Status badge temporarily hidden — will be redesigned later:
                     <x-status.index :resource="$application" :title="$lastDeploymentInfo" :lastDeploymentLink="$lastDeploymentLink" /> --}}
                <div class="application-heading-actions flex items-center gap-0.5 rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                    @if ($application->build_pack === 'dockercompose' && is_null($application->docker_compose_raw))
                        <span class="px-2 text-[13px] text-neutral-500 dark:text-fg-dim">Load a Compose file to deploy.</span>
                    @else
                        @if (!$application->destination->server->isSwarm())
                            <x-applications.advanced :application="$application" />
                        @endif
                        @if (!str($application->status)->startsWith('exited'))
                            @if (!$application->destination->server->isSwarm())
                                <x-forms.button canGate="deploy" :canResource="$application" title="With rolling update if possible" wire:click="deploy">
                                    <x-reicon name="refresh" class="size-4 text-orange-500 dark:text-orange-400" />
                                    Redeploy
                                </x-forms.button>
                            @endif
                            @if ($application->build_pack !== 'dockercompose')
                                @if ($application->destination->server->isSwarm())
                                    <x-forms.button canGate="deploy" :canResource="$application" title="Redeploy Swarm Service (rolling update)" wire:click="deploy">
                                        <x-reicon name="refresh" class="size-4 text-warning" />
                                        Update Service
                                    </x-forms.button>
                                @else
                                    <x-modal-confirmation title="Confirm Application Restart?" buttonTitle="Restart"
                                        submitAction="restart" :actions="[
                                            'This application will be restarted without rebuilding.',
                                        ]" :confirmWithText="false" :confirmWithPassword="false"
                                        step2ButtonText="Confirm">
                                        <x-slot:content>
                                            <x-forms.button canGate="deploy" :canResource="$application" title="Restart without rebuilding">
                                                <x-reicon name="restart" class="size-4 text-warning" />
                                                Restart
                                            </x-forms.button>
                                        </x-slot:content>
                                    </x-modal-confirmation>
                                @endif
                            @endif
                            <x-modal-confirmation :disabled="!auth()->user()->can('deploy', $application)" :authDisabled="!auth()->user()->can('deploy', $application)" title="Confirm Application Stopping?" buttonTitle="Stop"
                                submitAction="stop" :checkboxes="$checkboxes" :actions="[
                                    'This application will be stopped.',
                                    'All non-persistent data of this application will be deleted.',
                                ]" :confirmWithText="false" :confirmWithPassword="false"
                                step1ButtonText="Continue" step2ButtonText="Confirm">
                                <x-slot:button-title>
                                    <x-reicon name="stop" class="size-4 text-error" />
                                    Stop
                                </x-slot:button-title>
                            </x-modal-confirmation>
                        @else
                            <x-forms.button canGate="deploy" :canResource="$application" wire:click="deploy">
                                <x-reicon name="play-circle" class="size-4 text-warning" />
                                Deploy
                            </x-forms.button>
                        @endif
                    @endif
                </div>
            </div>
        </div>
        {{-- Spacer: keeps in-flow content clear of the fixed layer-2 nav on desktop --}}
        <div class="hidden lg:block lg:h-10" aria-hidden="true"></div>
    </div>
</nav>
