<div class="navbar-main">
    <a class="{{ request()->routeIs('project.application.configuration') ? 'text-white' : '' }}"
        href="{{ route('project.application.configuration', $parameters) }}">
        <button>Configuration</button>
    </a>
    <a class="{{ request()->routeIs('project.application.deployments') ? 'text-white' : '' }}"
        href="{{ route('project.application.deployments', $parameters) }}">
        <button>Deployments</button>
    </a>
    <x-applications.links :application="$application" />
    <div class="flex-1"></div>
    <x-applications.advanced :application="$application" />

    @if ($application->status !== 'exited')
        <button wire:click='deploy' class="flex items-center gap-2 cursor-pointer hover:text-white text-neutral-400">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-warning" viewBox="0 0 24 24" stroke-width="2"
                stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                <path
                    d="M10.09 4.01l.496 -.495a2 2 0 0 1 2.828 0l7.071 7.07a2 2 0 0 1 0 2.83l-7.07 7.07a2 2 0 0 1 -2.83 0l-7.07 -7.07a2 2 0 0 1 0 -2.83l3.535 -3.535h-3.988">
                </path>
                <path d="M7.05 11.038v-3.988"></path>
            </svg>
            Restart
        </button>
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
        <button wire:click='deploy' class="flex items-center gap-2 cursor-pointer hover:text-white text-neutral-400">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-warning" viewBox="0 0 24 24" stroke-width="1.5"
                stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                <path d="M7 4v16l13 -8z" />
            </svg>
            Deploy
        </button>
    @endif
</div>
