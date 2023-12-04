<div class="navbar-main">
    <a class="{{ request()->routeIs('project.application.configuration') ? 'text-white' : '' }}"
        href="{{ route('project.application.configuration', $parameters) }}">
        <button>Configuration</button>
    </a>
    <a class="{{ request()->routeIs('project.application.deployments') ? 'text-white' : '' }}"
        href="{{ route('project.application.deployments', $parameters) }}">
        <button>Deployments</button>
    </a>
    <a class="{{ request()->routeIs('project.application.logs') ? 'text-white' : '' }}"
        href="{{ route('project.application.logs', $parameters) }}">
        <button>Logs</button>
    </a>
    <x-applications.links :application="$application" />
    <div class="flex-1"></div>
    @if ($application->build_pack === 'dockercompose' && is_null($application->docker_compose_raw))
        <div>Please load a Compose file.</div>
    @elseif ($application->destination->server->isSwarm() && str($application->docker_registry_image_name)->isEmpty())
        Swarm Deployments requires a Docker Image in a Registry.
    @else
        <x-applications.advanced :application="$application" />
        @if ($application->status !== 'exited')
            <button title="With rolling update if possible" wire:click='deploy'
                class="flex items-center gap-2 cursor-pointer hover:text-white text-neutral-400">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-orange-400" viewBox="0 0 24 24"
                    stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                    <path
                        d="M10.09 4.01l.496 -.495a2 2 0 0 1 2.828 0l7.071 7.07a2 2 0 0 1 0 2.83l-7.07 7.07a2 2 0 0 1 -2.83 0l-7.07 -7.07a2 2 0 0 1 0 -2.83l3.535 -3.535h-3.988">
                    </path>
                    <path d="M7.05 11.038v-3.988"></path>
                </svg>
                Redeploy
            </button>
            @if ($application->build_pack !== 'dockercompose')
                <button title="Restart without rebuilding" wire:click='restart'
                    class="flex items-center gap-2 cursor-pointer hover:text-white text-neutral-400">
                    <svg class="w-5 h-5 text-warning" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2">
                            <path d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                            <path d="M20 4v5h-5" />
                        </g>
                    </svg>
                    Restart
                </button>
                @if (isDev())
                    <button title="Restart without rebuilding" wire:click='restartNew'
                        class="flex items-center gap-2 cursor-pointer hover:text-white text-neutral-400">
                        <svg class="w-5 h-5 text-warning" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2">
                                <path d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                                <path d="M20 4v5h-5" />
                            </g>
                        </svg>
                        Restart (new)
                    </button>
                @endif
            @endif
            <button wire:click='stop' class="flex items-center gap-2 cursor-pointer hover:text-white text-neutral-400">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-error" viewBox="0 0 24 24" stroke-width="2"
                    stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                    <path d="M6 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z"></path>
                    <path d="M14 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z"></path>
                </svg>
                Stop
            </button>
        @else
            <button wire:click='deploy'
                class="flex items-center gap-2 cursor-pointer hover:text-white text-neutral-400">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-warning" viewBox="0 0 24 24"
                    stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path d="M7 4v16l13 -8z" />
                </svg>
                Deploy
            </button>
            @if (isDev())
                <button wire:click='deployNew'
                    class="flex items-center gap-2 cursor-pointer hover:text-white text-neutral-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-warning" viewBox="0 0 24 24"
                        stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M7 4v16l13 -8z" />
                    </svg>
                    Deploy (new)
                </button>
            @endif
        @endif
    @endif
</div>
