<nav wire:poll.10000ms="checkStatus" class="w-full max-w-[1180px] pb-6 lg:pb-0">
    @php
        $databasePageItems = [
            [
                'label' => 'Settings',
                'route' => 'project.database.configuration',
                'active' => request()->routeIs('project.database.configuration')
                    || request()->routeIs('project.database.environment-variables')
                    || request()->routeIs('project.database.servers')
                    || request()->routeIs('project.database.import-backup')
                    || request()->routeIs('project.database.persistent-storage')
                    || request()->routeIs('project.database.healthcheck')
                    || request()->routeIs('project.database.webhooks')
                    || request()->routeIs('project.database.resource-limits')
                    || request()->routeIs('project.database.resource-operations')
                    || request()->routeIs('project.database.metrics')
                    || request()->routeIs('project.database.tags')
                    || request()->routeIs('project.database.danger'),
            ],
            [
                'label' => 'Backups',
                'route' => 'project.database.backup.index',
                'active' => request()->routeIs('project.database.backup.*'),
                'visible' => $database->isBackupSolutionAvailable(),
            ],
            [
                'label' => 'Runtime Logs',
                'route' => 'project.database.logs',
                'active' => request()->routeIs('project.database.logs'),
                'navigate' => false,
            ],
            [
                'label' => 'Console',
                'route' => 'project.database.command',
                'active' => request()->routeIs('project.database.command'),
                'navigate' => false,
                'visible' => auth()->user()?->can('canAccessTerminal'),
            ],
        ];

        $databasePageItems = array_values(array_filter(
            $databasePageItems,
            fn (array $item): bool => $item['visible'] ?? true,
        ));

        $databaseStatus = str($database->status ?? 'exited');
        [$databaseStatusLabel, $databaseStatusType] = match (true) {
            $databaseStatus->startsWith('running') => ['Running', 'success'],
            $databaseStatus->startsWith('degraded') => ['Degraded', 'warning'],
            $databaseStatus->startsWith('restarting'),
            $databaseStatus->startsWith('starting') => ['Starting', 'warning'],
            default => ['Stopped', 'neutral'],
        };
    @endphp

    <livewire:project.shared.configuration-checker :resource="$database" />

    <x-slide-over @startdatabase.window="slideOverOpen = true" closeWithX fullScreen>
        <x-slot:title>Database Startup</x-slot:title>
        <x-slot:content>
            <div wire:ignore class="h-full min-h-0 min-w-0 max-w-full">
                <livewire:activity-monitor header="Logs" fullHeight />
            </div>
        </x-slot:content>
    </x-slide-over>

    @teleport('#server-topbar-context')
        <div class="flex min-w-0 items-center gap-1 text-[13px]">
            <span class="shrink-0 px-0.5 text-neutral-300 dark:text-fg-faint">/</span>
            <span class="flex min-w-0 shrink items-center gap-2 px-1">
                <span class="max-w-48 min-w-0 truncate font-semibold text-black dark:text-fg xl:max-w-64">
                    {{ $database->name }}
                </span>
                <x-status-badge :status="$databaseStatusLabel" :type="$databaseStatusType" />
            </span>
        </div>
    @endteleport

    <div x-data>
        <div class="w-full md:hidden">
            <div class="mb-3 flex min-w-0 flex-wrap items-center gap-2">
                <span class="min-w-0 truncate text-sm font-medium text-neutral-700 dark:text-fg-dim">
                    {{ $database->name }}
                </span>
                <x-status-badge :status="$databaseStatusLabel" :type="$databaseStatusType" />
            </div>

            @if ($database->destination->server->isFunctional())
                <div class="mb-3 flex flex-nowrap items-center gap-2 overflow-x-auto">
                    @if (! $databaseStatus->startsWith('exited'))
                        <button type="button" class="button shrink-0"
                            @click="document.getElementById('database-restart-trigger')?.click()">
                            <x-reicon name="restart" class="size-4 text-orange-500 dark:text-warning" />
                            Restart
                        </button>
                        <button type="button" class="button shrink-0 text-error"
                            @click="document.getElementById('database-stop-trigger')?.click()">
                            <x-reicon name="stop" class="size-4 text-error" />
                            Stop
                        </button>
                    @else
                        <button type="button" class="button shrink-0" @click="$wire.dispatch('startEvent')">
                            <x-reicon name="play-circle" class="size-4 text-coollabs dark:text-warning" />
                            Start
                        </button>
                    @endif
                </div>
            @endif

            <div
                class="flex min-w-0 items-center gap-0.5 overflow-x-auto rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                @foreach ($databasePageItems as $menuItem)
                    <a @class([
                        'app-tab shrink-0',
                        'bg-coollabs/10 text-coollabs ring-1 ring-coollabs/25 dark:bg-warning/15 dark:text-warning dark:ring-warning/25' => $menuItem['active'],
                    ])
                        @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                        href="{{ route($menuItem['route'], $parameters) }}">
                        {{ $menuItem['label'] }}
                    </a>
                @endforeach
            </div>
        </div>

        <div class="hidden w-full items-center justify-between gap-4 md:flex lg:fixed lg:top-12 lg:right-0 lg:z-30 lg:h-12 lg:w-auto lg:border-b lg:border-neutral-200 lg:bg-white/95 lg:pr-4 lg:pl-2 lg:backdrop-blur lg:transition-[left] lg:duration-200 lg:dark:border-white/[0.06] lg:dark:bg-panel/95"
            :class="[typeof collapsed !== 'undefined' && collapsed ? 'lg:left-16' : 'lg:left-56']">
            <div
                class="flex min-w-0 items-center gap-0.5 overflow-x-auto rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                @foreach ($databasePageItems as $menuItem)
                    <a wire:key="database-primary-nav-{{ str($menuItem['label'])->slug() }}"
                        @class([
                            'app-tab shrink-0',
                            'bg-coollabs/10 text-coollabs shadow-sm ring-1 ring-coollabs/25 hover:bg-coollabs/15 dark:bg-warning/15 dark:text-warning dark:ring-warning/25 dark:hover:bg-warning/20' => $menuItem['active'],
                        ])
                        @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                        href="{{ route($menuItem['route'], $parameters) }}">
                        {{ $menuItem['label'] }}
                    </a>
                @endforeach
            </div>

            @if ($database->destination->server->isFunctional())
                <div
                    class="flex shrink-0 items-center gap-0.5 rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                    @if (! $databaseStatus->startsWith('exited'))
                        <x-forms.button canGate="manage" :canResource="$database"
                            @click="document.getElementById('database-restart-trigger')?.click()">
                            <x-reicon name="restart" class="size-4 text-orange-500 dark:text-warning" />
                            Restart
                        </x-forms.button>
                        <x-forms.button canGate="manage" :canResource="$database" isError
                            @click="document.getElementById('database-stop-trigger')?.click()">
                            <x-reicon name="stop" class="size-4 text-error" />
                            Stop
                        </x-forms.button>
                    @else
                        <x-forms.button canGate="manage" :canResource="$database"
                            @click="$wire.dispatch('startEvent')">
                            <x-reicon name="play-circle" class="size-4 text-warning" />
                            Start
                        </x-forms.button>
                    @endif
                </div>
            @else
                <x-status-badge status="Server unavailable" type="error" />
            @endif
        </div>

        <div class="hidden lg:block lg:h-10" aria-hidden="true"></div>
    </div>

    @if ($database->destination->server->isFunctional())
        <x-modal-confirmation title="Confirm Database Restart?" buttonTitle="Restart"
            submitAction="restartEvent" :actions="[
                'This database will be unavailable during the restart.',
                'If the database is currently in use, data could be lost.',
            ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Restart Database"
            :dispatchAction="true">
            <x-slot:trigger>
                <button id="database-restart-trigger" type="button" class="hidden">Restart</button>
            </x-slot:trigger>
        </x-modal-confirmation>
        <x-modal-confirmation title="Confirm Database Stopping?" buttonTitle="Stop" submitAction="stop"
            :checkboxes="$checkboxes" :actions="[
                'This database will be stopped.',
                'If the database is currently in use, data could be lost.',
                'Non-persistent containers, networks, and unused images will be removed.',
            ]" :confirmWithText="false" :confirmWithPassword="false"
            step1ButtonText="Continue" step2ButtonText="Confirm">
            <x-slot:trigger>
                <button id="database-stop-trigger" type="button" class="hidden">Stop</button>
            </x-slot:trigger>
        </x-modal-confirmation>
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
</nav>
