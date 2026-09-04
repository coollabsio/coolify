<nav wire:poll.10000ms="checkStatus" class="w-full max-w-none pb-4 md:pb-6 lg:pb-0">
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
                'label' => 'Terminal',
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
    @endphp

    <livewire:project.shared.configuration-checker :resource="$database" />

    <x-process-dialog @startdatabase.window="processDialogOpen = true" closeWithX>
        <x-slot:title>Database Startup</x-slot:title>
        <x-slot:content>
            <div wire:ignore class="flex h-full min-h-0 min-w-0 max-w-full flex-col">
                <livewire:activity-monitor header="Logs" fullHeight />
            </div>
        </x-slot:content>
    </x-process-dialog>

    <div x-data>
        <div class="mb-3 w-full xl:hidden">
            <div class="flex min-w-0 flex-col items-start gap-2">
                <h1 class="min-w-0 max-w-full truncate text-[24px]! leading-7! font-semibold! tracking-tight! text-black dark:text-fg">
                    {{ $database->name }}
                </h1>
                <div class="relative flex w-full min-w-0 items-center gap-2">
                    <x-status-summary :status="$database->status" title="Database status" />
                </div>
                <div class="flex w-full flex-wrap gap-1">
                    <x-application.restart-limit-warning :application="$database" />
                </div>
            </div>
        </div>

        <div class="w-full xl:hidden">
            @if ($database->destination->server->isFunctional())
                @can('manage', $database)
                <div id="database-mobile-actions" class="relative mb-3"
                    x-data="{ open: false }" @click.outside="open = false"
                    @keydown.escape.window="open = false">
                    <button type="button" class="button w-full justify-between" @click="open = !open"
                        :aria-expanded="open" aria-haspopup="menu">
                        <span class="inline-flex items-center gap-2">
                            Actions
                        </span>
                        <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
                            <x-reicon name="chevron-down" class="size-3 opacity-55" />
                        </span>
                    </button>

                    <div x-cloak x-show="open" x-transition.origin.top.left
                        class="listbox-panel top-full! left-0! right-0! mt-1! w-full! min-w-0!" role="menu">
                        @if (! $databaseStatus->startsWith('exited'))
                            @can('manage', $database)
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="open = false; document.getElementById('database-restart-trigger')?.click()"
                                    role="menuitem">
                                    <x-reicon name="restart" class="size-3.5 opacity-70" />
                                    Restart
                                </button>
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="open = false; document.getElementById('database-stop-trigger')?.click()"
                                    role="menuitem">
                                    <x-reicon name="stop" class="size-3.5 text-error" />
                                    Stop
                                </button>
                            @else
                                <button type="button" class="listbox-option justify-start! gap-2.5!" disabled
                                    role="menuitem">
                                    <x-reicon name="restart" class="size-3.5 opacity-70" />
                                    Restart
                                </button>
                                <button type="button" class="listbox-option justify-start! gap-2.5!" disabled
                                    role="menuitem">
                                    <x-reicon name="stop" class="size-3.5 opacity-70" />
                                    Stop
                                </button>
                            @endcan
                        @else
                            @can('manage', $database)
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="open = false; $wire.dispatch('startEvent')" role="menuitem">
                                    <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                                    Start
                                </button>
                            @else
                                <button type="button" class="listbox-option justify-start! gap-2.5!" disabled
                                    role="menuitem">
                                    <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                                    Start
                                </button>
                            @endcan
                        @endif
                    </div>
                </div>
                @endcan
            @endif

        </div>

        @teleport('#resource-action-hud-slot')
        <div class="hidden w-full items-center xl:flex xl:w-auto">
            <div
                class="resource-heading-navbar application-heading-actions flex w-auto min-w-0 items-center justify-end gap-1 overflow-visible">
                <div class="resource-heading-actions flex shrink-0 items-center gap-0.5">
                    @if ($database->destination->server->isFunctional())
                        @can('manage', $database)
                        <div id="database-desktop-actions" class="flex items-center gap-0.5">
                            @if (! $databaseStatus->startsWith('exited'))
                                <button type="button" class="button button-highlighted"
                                    @disabled(!auth()->user()->can('manage', $database))
                                    @click="document.getElementById('database-restart-trigger')?.click()">
                                    Restart
                                </button>
                                <button type="button" class="button"
                                    @disabled(!auth()->user()->can('manage', $database))
                                    @click="document.getElementById('database-stop-trigger')?.click()">
                                    Stop
                                </button>
                            @else
                                <x-forms.button class="button-highlighted" canGate="manage" :canResource="$database"
                                    @click="$wire.dispatch('startEvent')">
                                    Start
                                </x-forms.button>
                            @endif
                        </div>
                        @endcan
                    @else
                        <x-status-badge status="Server unavailable" type="error" />
                    @endif
                </div>
            </div>
        </div>
        @endteleport

    </div>

    @if ($database->destination->server->isFunctional())
        <div class="hidden" aria-hidden="true">
            <x-modal-confirmation title="Confirm Database Restart?" buttonTitle="Restart"
                submitAction="restartEvent" :actions="[
                    'This database will be unavailable during the restart.',
                    'If the database is currently in use, data could be lost.',
                ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Restart Database"
                :dispatchAction="true">
                <x-slot:trigger>
                    <button id="database-restart-trigger" type="button">Restart</button>
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
                    <button id="database-stop-trigger" type="button">Stop</button>
                </x-slot:trigger>
            </x-modal-confirmation>
        </div>
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
