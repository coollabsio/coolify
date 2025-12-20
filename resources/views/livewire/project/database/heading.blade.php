<nav wire:poll.10000ms="checkStatus" class="pb-6">
    <x-resources.breadcrumbs :resource="$database" :parameters="$parameters" />
    <x-slide-over @startdatabase.window="slideOverOpen = true" closeWithX fullScreen>
        <x-slot:title>{{ __('database.database_startup') }}</x-slot:title>
        <x-slot:content>
            <div wire:ignore>
                <livewire:activity-monitor header="{{ __('menu.logs') }}" fullHeight />
            </div>
        </x-slot:content>
    </x-slide-over>
    <div class="navbar-main">
        <nav
            class="flex overflow-x-scroll shrink-0 gap-6 items-center whitespace-nowrap sm:overflow-x-hidden scrollbar min-h-10">
            <a class="{{ request()->routeIs('project.database.configuration') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('project.database.configuration', $parameters) }}">
                {{ __('menu.configuration') }}
            </a>

            <a class="{{ request()->routeIs('project.database.logs') ? 'dark:text-white' : '' }}"
                href="{{ route('project.database.logs', $parameters) }}">
                {{ __('menu.logs') }}
            </a>
            @can('canAccessTerminal')
                <a class="{{ request()->routeIs('project.database.command') ? 'dark:text-white' : '' }}"
                    href="{{ route('project.database.command', $parameters) }}">
                    {{ __('menu.terminal') }}
                </a>
            @endcan
            @if (
                $database->getMorphClass() === 'App\Models\StandalonePostgresql' ||
                    $database->getMorphClass() === 'App\Models\StandaloneMongodb' ||
                    $database->getMorphClass() === 'App\Models\StandaloneMysql' ||
                    $database->getMorphClass() === 'App\Models\StandaloneMariadb')
                <a class="{{ request()->routeIs('project.database.backup.index') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                    href="{{ route('project.database.backup.index', $parameters) }}">
                    {{ __('menu.backups') }}
                </a>
            @endif
        </nav>
        @if ($database->destination->server->isFunctional())
            <div class="flex flex-wrap gap-2 items-center">
                @if (!str($database->status)->startsWith('exited'))
                    <x-modal-confirmation title="{{ __('database.confirm_restart_title') }}" buttonTitle="{{ __('database.restart') }}" submitAction="restart"
                        :actions="[
                            __('database.confirm_restart_action_1'),
                            __('database.confirm_restart_action_2'),
                        ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="{{ __('database.restart_database') }}"
                        :dispatchEvent="true" dispatchEventType="restartEvent">
                        <x-slot:button-title>
                            <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                                xmlns="http://www.w3.org/2000/svg">
                                <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="2">
                                    <path d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                                    <path d="M20 4v5h-5" />
                                </g>
                            </svg>
                            {{ __('database.restart') }}
                        </x-slot:button-title>
                    </x-modal-confirmation>
                    <x-modal-confirmation title="{{ __('database.confirm_stop_title') }}" buttonTitle="{{ __('database.stop') }}" submitAction="stop"
                        :checkboxes="$checkboxes" :actions="[
                            __('database.confirm_stop_action_1'),
                            __('database.confirm_stop_action_2'),
                            __('database.confirm_stop_action_3'),
                        ]" :confirmWithText="false" :confirmWithPassword="false"
                        step1ButtonText="Continue" step2ButtonText="Confirm">
                        <x-slot:button-title>
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-error" viewBox="0 0 24 24"
                                stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>
                                <path d="M6 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                                </path>
                                <path
                                    d="M14 5m0 1a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z">
                                </path>
                            </svg>
                            {{ __('database.stop') }}
                        </x-slot:button-title>
                    </x-modal-confirmation>
                @else
                    <button @click="$wire.dispatch('startEvent')" class="gap-2 button">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M7 4v16l13 -8z" />
                        </svg>
                        {{ __('database.start') }}
                    </button>
                @endif
                @script
                    <script>
                        $wire.$on('startEvent', () => {
                            window.dispatchEvent(new CustomEvent('startdatabase'));
                            $wire.$call('start');
                        });
                        $wire.$on('restartEvent', () => {
                            $wire.$dispatch('info', 'Restarting database.');
                            window.dispatchEvent(new CustomEvent('startdatabase'));
                            $wire.$call('restart');
                        });
                    </script>
                @endscript
            </div>
        @else
            <div class="text-error">{{ __('database.underlying_server_not_functional') }}</div>
        @endif
    </div>
</nav>
