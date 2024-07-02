<nav wire:poll.5000ms="check_status">
    <x-resources.breadcrumbs :resource="$database" :parameters="$parameters" />
    <x-slide-over @startdatabase.window="slideOverOpen = true" closeWithX fullScreen>
        <x-slot:title>Database Startup Logs</x-slot:title>
        <x-slot:content>
            <livewire:activity-monitor header="Logs" showWaiting />
        </x-slot:content>
    </x-slide-over>
    <div class="navbar-main">
        <nav
            class="flex items-center flex-shrink-0 gap-6 overflow-x-scroll sm:overflow-x-hidden scrollbar min-h-10 whitespace-nowrap">
            <a class="{{ request()->routeIs('project.database.configuration') ? 'dark:text-white' : '' }}"
                href="{{ route('project.database.configuration', $parameters) }}">
                <button>Configuration</button>
            </a>
            <a class="{{ request()->routeIs('project.database.command') ? 'dark:text-white' : '' }}"
                href="{{ route('project.database.command', $parameters) }}">
                <button>Execute Command</button>
            </a>
            <a class="{{ request()->routeIs('project.database.logs') ? 'dark:text-white' : '' }}"
                href="{{ route('project.database.logs', $parameters) }}">
                <button>Logs</button>
            </a>
            @if (
                $database->getMorphClass() === 'App\Models\StandalonePostgresql' ||
                    $database->getMorphClass() === 'App\Models\StandaloneMongodb' ||
                    $database->getMorphClass() === 'App\Models\StandaloneMysql' ||
                    $database->getMorphClass() === 'App\Models\StandaloneMariadb')
                <a class="{{ request()->routeIs('project.database.backup.index') ? 'dark:text-white' : '' }}"
                    href="{{ route('project.database.backup.index', $parameters) }}">
                    <button>Backups</button>
                </a>
            @endif
        </nav>
        <div class="flex flex-wrap items-center gap-2">
            @if (!str($database->status)->startsWith('exited'))
                <x-modal-confirmation @click="$wire.dispatch('restartEvent')">
                    <x-slot:button-title>
                        <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2">
                                <path d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                                <path d="M20 4v5h-5" />
                            </g>
                        </svg>
                        Restart
                    </x-slot:button-title>
                    This database will be restarted. <br>Please think again.
                </x-modal-confirmation>
                <x-modal-confirmation @click="$wire.dispatch('stopEvent')">
                    <x-slot:button-title>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-error" viewBox="0 0 24 24"
                            stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                            <path d="M6 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                            </path>
                            <path d="M14 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                            </path>
                        </svg>
                        Stop
                    </x-slot:button-title>
                    This database will be stopped. <br>Please think again.
                </x-modal-confirmation>
            @else
                <button @click="$wire.dispatch('startEvent')" class="gap-2 button">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                        stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M7 4v16l13 -8z" />
                    </svg>
                    Start
                </button>
            @endif
            @script
                <script>
                    $wire.$on('startEvent', () => {
                        window.dispatchEvent(new CustomEvent('startdatabase'));
                        $wire.$call('start');
                    });
                    $wire.$on('stopEvent', () => {
                        $wire.$dispatch('info', 'Stopping database.');
                        $wire.$call('stop');
                    });
                    $wire.$on('restartEvent', () => {
                        $wire.$dispatch('info', 'Restarting database.');
                        $wire.$call('restart');
                    });
                </script>
            @endscript
        </div>
    </div>
</nav>
