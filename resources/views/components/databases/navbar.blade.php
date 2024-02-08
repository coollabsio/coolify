<div class="navbar-main">
    <a  class="{{ request()->routeIs('project.database.configuration') ? 'text-white' : '' }}"
        href="{{ route('project.database.configuration', $parameters) }}">
        <button>Configuration</button>
    </a>
    <a  class="{{ request()->routeIs('project.database.command') ? 'text-white' : '' }}"
        href="{{ route('project.database.command', $parameters) }}">
        <button>Execute Command</button>
    </a>
    <a  class="{{ request()->routeIs('project.database.logs') ? 'text-white' : '' }}"
        href="{{ route('project.database.logs', $parameters) }}">
        <button>Logs</button>
    </a>
    @if (
        $database->getMorphClass() === 'App\Models\StandalonePostgresql' ||
            $database->getMorphClass() === 'App\Models\StandaloneMongodb' ||
            $database->getMorphClass() === 'App\Models\StandaloneMysql' ||
            $database->getMorphClass() === 'App\Models\StandaloneMariadb')
        <a  class="{{ request()->routeIs('project.database.backup.index') ? 'text-white' : '' }}"
            href="{{ route('project.database.backup.index', $parameters) }}">
            <button>Backups</button>
        </a>
    @endif
    <div class="flex-1"></div>
    @if (!str($database->status)->startsWith('exited'))
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
