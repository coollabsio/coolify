<nav wire:poll.10000ms="check_status">
    <x-resources.breadcrumbs :resource="$application" :parameters="$parameters" :title="$lastDeploymentInfo" :lastDeploymentLink="$lastDeploymentLink" />
    <div class="navbar-main">
        <nav class="flex flex-shrink-0 gap-6 items-center whitespace-nowrap scrollbar min-h-10">
            <a class="{{ request()->routeIs('project.application.configuration') ? 'dark:text-white' : '' }}"
                wire:navigate href="{{ route('project.application.configuration', $parameters) }}">
                Configuration
            </a>
            <a class="{{ request()->routeIs('project.application.deployment.index') ? 'dark:text-white' : '' }}"
                wire:navigate href="{{ route('project.application.deployment.index', $parameters) }}">
                <button>Deployments</button>
            </a>
            <a class="{{ request()->routeIs('project.application.logs') ? 'dark:text-white' : '' }}"
                wire:navigate href="{{ route('project.application.logs', $parameters) }}">
                <button>Logs</button>
            </a>
            @if (!$application->destination->server->isSwarm())
                <a class="{{ request()->routeIs('project.application.command') ? 'dark:text-white' : '' }}"
                 href="{{ route('project.application.command', $parameters) }}">
                    <button>Terminal</button>
                </a>
            @endif
            <x-applications.links :application="$application" />
        </nav>
        <div class="flex flex-wrap gap-2 items-center">
            @if ($application->build_pack === 'dockercompose' && is_null($application->docker_compose_raw))
                <div>Please load a Compose file.</div>
            @else
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
                            step1ButtonText="Continue" step2ButtonText="Confirm" :dispatchEvent="true"
                            dispatchEventType="stopEvent">
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
            @endif
        </div>

    </div>
    @script
        <script>
            $wire.$on('stopEvent', () => {
                $wire.$dispatch('info', 'Stopping application.');
                $wire.$call('stop');
            });
        </script>
    @endscript
</nav>
