<nav wire:poll.10000ms="checkStatus" class="pb-6">
    <x-resources.breadcrumbs :resource="$application" :parameters="$parameters" :title="$lastDeploymentInfo" :lastDeploymentLink="$lastDeploymentLink" />
    <div class="navbar-main">
        <nav
            class="scrollbar flex min-h-10 w-full flex-nowrap items-center gap-6 overflow-x-scroll overflow-y-hidden pb-1 whitespace-nowrap md:w-auto md:overflow-visible">
            <a class="shrink-0 {{ request()->routeIs('project.application.configuration') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('project.application.configuration', $parameters) }}">
                Configuration
            </a>
            <a class="shrink-0 {{ request()->routeIs('project.application.deployment.index') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('project.application.deployment.index', $parameters) }}">
                Deployments
            </a>
            <a class="shrink-0 {{ request()->routeIs('project.application.logs') ? 'dark:text-white' : '' }}"
                href="{{ route('project.application.logs', $parameters) }}">
                <div class="flex items-center gap-1">
                    Logs
                    @if ($application->restart_count > 0 && !str($application->status)->startsWith('exited'))
                        <svg class="w-4 h-4 dark:text-warning" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" title="Container has restarted {{ $application->restart_count }} time{{ $application->restart_count > 1 ? 's' : '' }}">
                            <path d="M12 2L1 21h22L12 2zm0 4l7.53 13H4.47L12 6zm-1 5v4h2v-4h-2zm0 5v2h2v-2h-2z"/>
                        </svg>
                    @endif
                </div>
            </a>
            @if (!$application->destination->server->isSwarm())
                @can('canAccessTerminal')
                    <a class="shrink-0 {{ request()->routeIs('project.application.command') ? 'dark:text-white' : '' }}"
                        href="{{ route('project.application.command', $parameters) }}">
                        Terminal
                    </a>
                @endcan
            @endif
            <div class="shrink-0">
                <x-applications.links :application="$application" />
            </div>
        </nav>
        <div class="flex flex-wrap gap-2 items-center">
            @if ($application->build_pack === 'dockercompose' && is_null($application->docker_compose_raw))
                <div>Please load a Compose file.</div>
            @else
                <div class="md:hidden">
                    <x-dropdown>
                        <x-slot:title>
                            Actions
                        </x-slot>
                        @if (!str($application->status)->startsWith('exited'))
                            @if (!$application->destination->server->isSwarm())
                                <div class="dropdown-item dropdown-item-touch" wire:click='deploy'>
                                    Redeploy
                                </div>
                            @endif
                            @if ($application->build_pack !== 'dockercompose')
                                @if ($application->destination->server->isSwarm())
                                    <div class="dropdown-item dropdown-item-touch" wire:click='deploy'>
                                        Update Service
                                    </div>
                                @else
                                    <div class="dropdown-item dropdown-item-touch" wire:click='restart'>
                                        Restart
                                    </div>
                                @endif
                            @endif
                            <x-modal-confirmation title="Confirm Application Stopping?" buttonTitle="Stop"
                                submitAction="stop" :checkboxes="$checkboxes" :actions="[
                                    'This application will be stopped.',
                                    'All non-persistent data of this application will be deleted.',
                                ]" :confirmWithText="false" :confirmWithPassword="false"
                                step1ButtonText="Continue" step2ButtonText="Confirm">
                                <x-slot:trigger>
                                    <div class="dropdown-item dropdown-item-touch text-error">
                                        Stop
                                    </div>
                                </x-slot:trigger>
                            </x-modal-confirmation>
                        @else
                            <div class="dropdown-item dropdown-item-touch" wire:click='deploy'>
                                Deploy
                            </div>
                        @endif

                        @if (!$application->destination->server->isSwarm())
                            <div class="mx-2 my-1 border-t border-neutral-200 dark:border-coolgray-300"></div>

                            @if ($application->status === 'running')
                                <div class="dropdown-item dropdown-item-touch" wire:click='force_deploy_without_cache'>
                                    Force deploy (without cache)
                                </div>
                            @else
                                <div class="dropdown-item dropdown-item-touch" wire:click='deploy(true)'>
                                    Force deploy (without cache)
                                </div>
                            @endif
                        @endif
                    </x-dropdown>
                </div>

                <div class="hidden flex-wrap items-center gap-2 md:flex">
                    @if (!$application->destination->server->isSwarm())
                        <div>
                            <x-applications.advanced :application="$application" />
                        </div>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        @if (!str($application->status)->startsWith('exited'))
                            @if (!$application->destination->server->isSwarm())
                                <x-forms.button title="With rolling update if possible" wire:click='deploy'>
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
                            @endif
                            @if ($application->build_pack !== 'dockercompose')
                                @if ($application->destination->server->isSwarm())
                                    <x-forms.button title="Redeploy Swarm Service (rolling update)" wire:click='deploy'>
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
                                @else
                                    <x-forms.button title="Restart without rebuilding" wire:click='restart'>
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
                            <x-forms.button wire:click='deploy'>
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning"
                                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                    <path d="M7 4v16l13 -8z" />
                                </svg>
                                Deploy
                            </x-forms.button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</nav>
