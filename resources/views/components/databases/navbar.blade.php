<div class="navbar-main">
    <a class="{{ request()->routeIs('project.database.configuration') ? 'text-white' : '' }}"
        href="{{ route('project.database.configuration', $parameters) }}">
        <button>Configuration</button>
    </a>
    <a class="{{ request()->routeIs('project.database.backups.all') ? 'text-white' : '' }}"
        href="{{ route('project.database.backups.all', $parameters) }}">
        <button>Backups</button>
    </a>
    {{-- <x-applications.links :application="$application" /> --}}
    <div class="flex-1"></div>
    {{-- <x-applications.advanced :application="$application" /> --}}

    @if ($database->status !== 'exited')
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
        <button wire:click='start' onclick="startDatabase.showModal()"
            class="flex items-center gap-2 cursor-pointer hover:text-white text-neutral-400">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-warning" viewBox="0 0 24 24" stroke-width="1.5"
                stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                <path d="M7 4v16l13 -8z" />
            </svg>
            Start
        </button>
    @endif
</div>
