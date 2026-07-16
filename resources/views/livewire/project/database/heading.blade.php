<nav wire:poll.10000ms="checkStatus" class="pb-6">
    @php
        $databasePageItems = [
            ['label' => 'Configuration', 'route' => 'project.database.configuration', 'active' => request()->routeIs('project.database.configuration')],
            ['label' => 'Logs', 'route' => 'project.database.logs', 'active' => request()->routeIs('project.database.logs')],
            ['label' => 'Terminal', 'route' => 'project.database.command', 'active' => request()->routeIs('project.database.command'), 'navigate' => false, 'visible' => auth()->user()?->can('canAccessTerminal')],
            [
                'label' => 'Backups',
                'route' => 'project.database.backup.index',
                'active' => request()->routeIs('project.database.backup.index', 'project.database.backup.execution'),
                'visible' => $database->isBackupSolutionAvailable(),
            ],
        ];

        $databaseConfigurationItems = [
            ['label' => 'General', 'route' => 'project.database.configuration', 'active' => request()->routeIs('project.database.configuration')],
            ['label' => 'Environment Variables', 'route' => 'project.database.environment-variables', 'active' => request()->routeIs('project.database.environment-variables')],
            ['label' => 'Servers', 'route' => 'project.database.servers', 'active' => request()->routeIs('project.database.servers')],
            ['label' => 'Persistent Storage', 'route' => 'project.database.persistent-storage', 'active' => request()->routeIs('project.database.persistent-storage')],
            ['label' => 'Import Backup', 'route' => 'project.database.import-backup', 'active' => request()->routeIs('project.database.import-backup'), 'visible' => auth()->user()?->can('update', $database)],
            ['label' => 'Webhooks', 'route' => 'project.database.webhooks', 'active' => request()->routeIs('project.database.webhooks')],
            ['label' => 'Healthcheck', 'route' => 'project.database.healthcheck', 'active' => request()->routeIs('project.database.healthcheck')],
            ['label' => 'Resource Limits', 'route' => 'project.database.resource-limits', 'active' => request()->routeIs('project.database.resource-limits')],
            ['label' => 'Resource Operations', 'route' => 'project.database.resource-operations', 'active' => request()->routeIs('project.database.resource-operations')],
            ['label' => 'Metrics', 'route' => 'project.database.metrics', 'active' => request()->routeIs('project.database.metrics')],
            ['label' => 'Tags', 'route' => 'project.database.tags', 'active' => request()->routeIs('project.database.tags')],
            ['label' => 'Danger Zone', 'route' => 'project.database.danger', 'active' => request()->routeIs('project.database.danger')],
        ];

        $databasePageItems = array_values(array_filter($databasePageItems, fn (array $item): bool => $item['visible'] ?? true));
        $databaseConfigurationItems = array_values(array_filter($databaseConfigurationItems, fn (array $item): bool => $item['visible'] ?? true));
        $activeDatabaseConfigurationItem = collect($databaseConfigurationItems)->firstWhere('active', true);
        $activeDatabasePageItem = collect($databasePageItems)->firstWhere('active', true);
        $activeDatabaseMobileItem = $activeDatabaseConfigurationItem ?? $activeDatabasePageItem ?? $databasePageItems[0];
        $activeDatabaseMobileGroup = $activeDatabaseConfigurationItem ? 'configuration' : 'database';
        $activeDatabaseMobileNavigation = ($activeDatabaseMobileItem['navigate'] ?? true) ? 'navigate' : 'location';
        $activeDatabaseMobileValue = $activeDatabaseMobileNavigation.'|'.$activeDatabaseMobileGroup.'|'.route($activeDatabaseMobileItem['route'], $parameters);
        $databaseMobileMenuChangeHandler = <<<'JS'
            const value = $event.target.value;

            if (!value) {
                return;
            }

            if (value.startsWith('navigate|')) {
                const url = value.split('|').slice(2).join('|');
                window.Livewire?.navigate ? window.Livewire.navigate(url) : window.location.href = url;
                return;
            }

            if (value.startsWith('location|')) {
                const url = value.split('|').slice(2).join('|');
                window.location.href = url;
                return;
            }

            resetToCurrent();

            if (value.startsWith('external:')) {
                window.open(value.slice(9), '_blank', 'noopener');
                return;
            }

            document.getElementById(`database-${value.slice(7)}-trigger`)?.click();
        JS;
    @endphp
    <x-resources.breadcrumbs :resource="$database" :parameters="$parameters" />
    <x-slide-over @startdatabase.window="slideOverOpen = true" closeWithX fullScreen>
        <x-slot:title>Database Startup</x-slot:title>
        <x-slot:content>
            <div wire:ignore class="h-full min-h-0 min-w-0 max-w-full">
                <livewire:activity-monitor header="Logs" fullHeight />
            </div>
        </x-slot:content>
    </x-slide-over>
    <div class="navbar-main">
        <div class="w-full md:hidden">
            @if ($database->destination->server->isFunctional())
                <div id="database-mobile-actions" class="mt-2 mb-3 md:hidden">
                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Actions</div>
                    <div class="flex flex-nowrap items-center gap-2 overflow-x-auto">
                    @if (!str($database->status)->startsWith('exited'))
                        <button type="button" class="button shrink-0"
                            @click="document.getElementById('database-restart-trigger')?.click()">
                            <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                                xmlns="http://www.w3.org/2000/svg">
                                <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="2">
                                    <path d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                                    <path d="M20 4v5h-5" />
                                </g>
                            </svg>
                            Restart
                        </button>
                        <x-forms.button isError class="shrink-0"
                            @click="document.getElementById('database-stop-trigger')?.click()">
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
                            Stop
                        </x-forms.button>
                    @else
                        <button type="button" class="button shrink-0"
                            @click="document.getElementById('database-start-trigger')?.click()">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M7 4v16l13 -8z" />
                            </svg>
                            Start
                        </button>
                    @endif
                    </div>
                </div>
            @endif
            <label id="database-mobile-section-label" for="database-mobile-section" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Section</label>
            <select id="database-mobile-section" class="select w-full" aria-label="Database menu"
                data-current-value="{{ $activeDatabaseMobileValue }}"
                x-data="{
                    init() {
                        this.syncFromLocation();
                        window.Livewire?.hook?.('morphed', ({ el }) => {
                            if (el.contains(this.$el)) {
                                queueMicrotask(() => this.syncFromLocation());
                            }
                        });
                    },
                    selected: $el.dataset.currentValue,
                    current: $el.dataset.currentValue,
                    syncFromLocation() {
                        const currentUrl = new URL(window.location.href);
                        const matchingOptions = Array.from(this.$el.options).filter((option) => {
                            if (!option.value.startsWith('navigate|') && !option.value.startsWith('location|')) {
                                return false;
                            }

                            const optionUrl = new URL(option.value.split('|').slice(2).join('|'), window.location.origin);

                            return optionUrl.pathname === currentUrl.pathname;
                        });
                        const selectedOption = matchingOptions.find((option) => {
                            return option.value.startsWith('navigate|configuration|') || option.value.startsWith('navigate|resource|');
                        }) || matchingOptions[0];

                        if (selectedOption) {
                            this.current = selectedOption.value;
                            this.selected = selectedOption.value;
                        }
                    },
                    resetToCurrent() {
                        this.selected = this.current;
                    },
                }"
                x-on:livewire:navigated.window="syncFromLocation()"
                x-model="selected"
                x-on:change="{{ $databaseMobileMenuChangeHandler }}">
                <optgroup label="Database">
                    @foreach ($databasePageItems as $menuItem)
                        <option value="{{ ($menuItem['navigate'] ?? true) ? 'navigate' : 'location' }}|database|{{ route($menuItem['route'], $parameters) }}">
                            {{ $menuItem['label'] }}
                        </option>
                    @endforeach
                </optgroup>
                <optgroup label="Configuration">
                    @foreach ($databaseConfigurationItems as $menuItem)
                        <option value="navigate|configuration|{{ route($menuItem['route'], $parameters) }}">
                            {{ $menuItem['label'] }}
                        </option>
                    @endforeach
                </optgroup>
            </select>
        </div>
        <nav
            class="scrollbar hidden min-h-10 w-full flex-nowrap items-center gap-6 overflow-x-scroll overflow-y-hidden pb-1 whitespace-nowrap md:flex md:w-auto md:overflow-visible">
            <a class="shrink-0 {{ request()->routeIs('project.database.configuration') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('project.database.configuration', $parameters) }}">
                Configuration
            </a>

            <a class="shrink-0 {{ request()->routeIs('project.database.logs') ? 'dark:text-white' : '' }}"
                href="{{ route('project.database.logs', $parameters) }}">
                Logs
            </a>
            @can('canAccessTerminal')
                <a class="shrink-0 {{ request()->routeIs('project.database.command') ? 'dark:text-white' : '' }}"
                    href="{{ route('project.database.command', $parameters) }}">
                    Terminal
                </a>
            @endcan
            @if ($database->isBackupSolutionAvailable())
                <a class="shrink-0 {{ request()->routeIs('project.database.backup.index') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                    href="{{ route('project.database.backup.index', $parameters) }}">
                    Backups
                </a>
            @endif
        </nav>

        @if ($database->destination->server->isFunctional())
            <div class="flex flex-wrap gap-2 items-center">
                <div class="hidden flex-wrap items-center gap-2 md:flex">
                    @if (!str($database->status)->startsWith('exited'))

                        <x-forms.button canGate="manage" :canResource="$database" title="Restart" @click="document.getElementById('database-restart-trigger')?.click()">
                            <svg class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                                xmlns="http://www.w3.org/2000/svg">
                                <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="2">
                                    <path d="M19.933 13.041a8 8 0 1 1-9.925-8.788c3.899-1 7.935 1.007 9.425 4.747" />
                                    <path d="M20 4v5h-5" />
                                </g>
                            </svg>
                            Restart
                        </x-forms.button>
                        <x-forms.button canGate="manage" :canResource="$database" isError title="Stop" @click="document.getElementById('database-stop-trigger')?.click()">
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
                            Stop
                        </x-forms.button>
                    @else
                        <x-forms.button canGate="manage" :canResource="$database" @click="document.getElementById('database-start-trigger')?.click()" class="gap-2">

                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 dark:text-warning" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                <path d="M7 4v16l13 -8z" />
                            </svg>
                            Start
                        </x-forms.button>
                    @endif
                </div>
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
            <div class="text-error">Underlying server is not functional.</div>
        @endif
    </div>
    @if ($database->destination->server->isFunctional())
            <x-modal-confirmation title="Confirm Database Restart?" buttonTitle="Restart" submitAction="restartEvent"
                :actions="[
                    'This database will be unavailable during the restart.',
                    'If the database is currently in use data could be lost.',
                ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Restart Database"
                :dispatchAction="true">
                <x-slot:trigger>
                    <button id="database-restart-trigger" type="button" class="hidden">Restart</button>
                </x-slot:trigger>
            </x-modal-confirmation>
            <x-modal-confirmation title="Confirm Database Stopping?" buttonTitle="Stop" submitAction="stop"
                :checkboxes="$checkboxes" :actions="[
                    'This database will be stopped.',
                    'If the database is currently in use data could be lost.',
                    'All non-persistent data of this database (containers, networks, unused images) will be deleted (don\'t worry, no data is lost and you can start the database again).',
                ]" :confirmWithText="false" :confirmWithPassword="false"
                step1ButtonText="Continue" step2ButtonText="Confirm">
                <x-slot:trigger>
                    <button id="database-stop-trigger" type="button" class="hidden">Stop</button>
                </x-slot:trigger>
            </x-modal-confirmation>
            <x-modal-confirmation title="Confirm Database Start?" buttonTitle="Start" submitAction="startEvent"
                :actions="[
                    'This database will be started.',
                ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Start Database"
                :dispatchAction="true">
                <x-slot:trigger>
                    <button id="database-start-trigger" type="button" class="hidden">Start</button>
                </x-slot:trigger>
            </x-modal-confirmation>
    @endif

</nav>
