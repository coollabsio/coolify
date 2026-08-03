<nav wire:poll.10000ms="checkStatus" class="w-full max-w-[1180px] pb-4 md:pb-6 lg:pb-0">
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
                'label' => 'Domains',
                'route' => 'project.application.domains',
                'active' => $routeIs('project.application.domains'),
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
        $applicationStatus = str($application->status ?? 'exited');
        [$applicationStatusLabel, $applicationStatusType] = match (true) {
            $applicationStatus->startsWith('running') => ['Running', 'success'],
            $applicationStatus->startsWith('degraded') => ['Degraded', 'warning'],
            $applicationStatus->startsWith('restarting'),
            $applicationStatus->startsWith('starting') => ['Starting', 'warning'],
            $applicationStatus->startsWith('exited') => ['Stopped', 'neutral'],
            default => ['Deploying', 'warning'],
        };
    @endphp
    <div>
        <div class="mb-3 w-full lg:hidden">
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <h1 class="min-w-0 truncate text-[24px]! leading-7! font-semibold! tracking-tight! text-black dark:text-fg">
                    {{ $application->name }}
                </h1>
                <x-status-badge :status="$applicationStatusLabel" :type="$applicationStatusType" />
            </div>
        </div>

        <div class="w-full md:hidden">
            @if (!($application->build_pack === 'dockercompose' && is_null($application->docker_compose_raw)))
                <div id="application-mobile-actions" class="relative mb-3"
                    x-data="{ open: false }" @click.outside="open = false"
                    @keydown.escape.window="open = false">
                    <button type="button" class="button w-full justify-between" @click="open = !open"
                        :aria-expanded="open" aria-haspopup="menu">
                        <span class="inline-flex items-center gap-2">
                            <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                            Actions
                        </span>
                        <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
                            <x-reicon name="chevron-down" class="size-3 opacity-55" />
                        </span>
                    </button>

                    <div x-cloak x-show="open" x-transition.origin.top.left
                        class="listbox-panel top-full! left-0! right-0! mt-1! w-full! min-w-0!" role="menu">
                        @if (!str($application->status)->startsWith('exited'))
                            @if (!$application->destination->server->isSwarm())
                                @can('deploy', $application)
                                    <button type="button" class="listbox-option justify-start! gap-2.5!"
                                        wire:click="deploy" @click="open = false" role="menuitem">
                                        <x-reicon name="refresh" class="size-3.5 text-orange-500 dark:text-orange-400" />
                                        Redeploy
                                    </button>
                                @else
                                    <button type="button" class="listbox-option justify-start! gap-2.5!" disabled
                                        role="menuitem">
                                        <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                        Redeploy
                                    </button>
                                @endcan
                            @endif
                            @if ($application->build_pack !== 'dockercompose')
                                @if ($application->destination->server->isSwarm())
                                    @can('deploy', $application)
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            wire:click="deploy" @click="open = false" role="menuitem">
                                            <x-reicon name="refresh" class="size-3.5 text-warning" />
                                            Update Service
                                        </button>
                                    @else
                                        <button type="button" class="listbox-option justify-start! gap-2.5!" disabled
                                            role="menuitem">
                                            <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                            Update Service
                                        </button>
                                    @endcan
                                @else
                                    @can('deploy', $application)
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            @click="open = false; document.getElementById('application-mobile-restart-trigger')?.click()"
                                            role="menuitem">
                                            <x-reicon name="restart" class="size-3.5 text-warning" />
                                            Restart
                                        </button>
                                    @else
                                        <button type="button" class="listbox-option justify-start! gap-2.5!" disabled
                                            role="menuitem">
                                            <x-reicon name="restart" class="size-3.5 opacity-70" />
                                            Restart
                                        </button>
                                    @endcan
                                @endif
                            @endif
                            @can('deploy', $application)
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="open = false; document.getElementById('application-mobile-stop-trigger')?.click()"
                                    role="menuitem">
                                    <x-reicon name="stop" class="size-3.5 text-error" />
                                    Stop
                                </button>
                            @else
                                <button type="button" class="listbox-option justify-start! gap-2.5!" disabled
                                    role="menuitem">
                                    <x-reicon name="stop" class="size-3.5 opacity-70" />
                                    Stop
                                </button>
                            @endcan
                        @else
                            @can('deploy', $application)
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    wire:click="deploy" @click="open = false" role="menuitem">
                                    <x-reicon name="play-circle" class="size-3.5 text-warning" />
                                    Deploy
                                </button>
                            @else
                                <button type="button" class="listbox-option justify-start! gap-2.5!" disabled
                                    role="menuitem">
                                    <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                                    Deploy
                                </button>
                            @endcan
                        @endif
                        @if (!$application->destination->server->isSwarm())
                            @can('deploy', $application)
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    wire:click="{{ $application->status === 'running' ? 'force_deploy_without_cache' : 'deploy(true)' }}"
                                    @click="open = false" role="menuitem">
                                    <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                    Force deploy without cache
                                </button>
                            @else
                                <button type="button" class="listbox-option justify-start! gap-2.5!" disabled
                                    role="menuitem">
                                    <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                    Force deploy without cache
                                </button>
                            @endcan
                        @endif
                    </div>
                </div>
            @endif
            <div
                class="flex min-w-0 items-center gap-0.5 rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                {{-- Tabs may scroll; keep Links outside overflow so the dropdown never creates a scrollbar. --}}
                <x-resource-heading-tabs class="min-w-0 flex-1">
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
                </x-resource-heading-tabs>
                <div class="resource-heading-menus shrink-0">
                    <x-applications.links :application="$application" />
                </div>
            </div>
            <div class="hidden" aria-hidden="true">
                <x-modal-confirmation title="Confirm Application Stopping?" buttonTitle="Stop"
                    submitAction="stop" :checkboxes="$checkboxes" :actions="[
                        'This application will be stopped.',
                        'All non-persistent data of this application will be deleted.',
                    ]" :confirmWithText="false" :confirmWithPassword="false"
                    step1ButtonText="Continue" step2ButtonText="Confirm">
                    <x-slot:trigger>
                        <button id="application-mobile-stop-trigger" type="button">Stop</button>
                    </x-slot:trigger>
                </x-modal-confirmation>
                <x-modal-confirmation title="Confirm Application Restart?" buttonTitle="Restart"
                    submitAction="restart" :actions="[
                        'This application will be restarted without rebuilding.',
                    ]" :confirmWithText="false" :confirmWithPassword="false"
                    step2ButtonText="Confirm">
                    <x-slot:trigger>
                        <button id="application-mobile-restart-trigger" type="button">Restart</button>
                    </x-slot:trigger>
                </x-modal-confirmation>
            </div>
        </div>

        {{-- Layer-2 top nav: one unified bar — menus left, actions right --}}
        <div class="hidden w-full items-center md:flex lg:fixed lg:top-12 lg:right-0 lg:z-30 lg:h-12 lg:w-auto lg:border-b lg:border-neutral-200 lg:bg-white/95 lg:pl-2 lg:pr-4 lg:backdrop-blur lg:transition-[left] lg:duration-200 lg:dark:border-white/[0.06] lg:dark:bg-panel/95"
            :class="[typeof collapsed !== 'undefined' && collapsed ? 'lg:left-16' : 'lg:left-56']">
            <div
                class="resource-heading-navbar application-heading-actions flex w-full min-w-0 items-center justify-between gap-2 overflow-visible rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                <div class="application-primary-tabs flex min-w-0 items-center gap-0.5">
                    {{-- Tabs alone may scroll; keep Links outside overflow so the dropdown never creates a scrollbar. --}}
                    <x-resource-heading-tabs class="min-w-0">
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
                    </x-resource-heading-tabs>
                    <div class="resource-heading-menus shrink-0">
                        <x-applications.links :application="$application" />
                    </div>
                </div>
                <div class="resource-heading-actions flex shrink-0 items-center gap-0.5 border-l border-neutral-200 pl-1 dark:border-white/[0.08]">
                    {{-- Status badge temporarily hidden — will be redesigned later:
                         <x-status.index :resource="$application" :title="$lastDeploymentInfo" :lastDeploymentLink="$lastDeploymentLink" /> --}}
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
        {{-- Spacer: in-flow stand-in for the fixed layer-2 nav (h-12). Not visible content — keeps page body clear of the bar. --}}
        <div class="hidden lg:block lg:h-12" aria-hidden="true"></div>
    </div>
</nav>
