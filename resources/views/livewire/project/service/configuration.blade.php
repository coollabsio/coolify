<div>
    <x-slot:title>
        {{ data_get_str($service, 'name')->limit(10) }} > Configuration | Coolify
    </x-slot>

    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="$query" />

    @php
        $serviceRouteParameters = [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'service_uuid' => $service->uuid,
        ];

        $configurationItems = collect([
            ['label' => 'General', 'route' => 'project.service.configuration', 'icon' => 'settings'],
            ['label' => 'Domains', 'route' => 'project.service.domains', 'icon' => 'globe'],
            ['label' => 'Environment Variables', 'route' => 'project.service.environment-variables', 'icon' => 'variables', 'hasWarning' => ! $service->isDeployable],
            ['label' => 'Persistent Storage', 'route' => 'project.service.storages', 'icon' => 'storages'],
            ['label' => 'Backups', 'route' => 'project.service.volume-backups.index', 'icon' => 'database'],
            ['label' => 'Runtime Logs', 'route' => 'project.service.logs', 'icon' => 'unordered-list', 'navigate' => false],
            ['label' => 'Terminal', 'route' => 'project.service.command', 'icon' => 'browser-terminal', 'navigate' => false, 'visible' => auth()->user()?->can('canAccessTerminal')],
            ['label' => 'Scheduled Tasks', 'route' => 'project.service.scheduled-tasks.show', 'icon' => 'calendar'],
            ['label' => 'Webhooks', 'route' => 'project.service.webhooks', 'icon' => 'notifications'],
            ['label' => 'Resource Operations', 'route' => 'project.service.resource-operations', 'icon' => 'server-update'],
            ['label' => 'Tags', 'route' => 'project.service.tags', 'icon' => 'tags'],
            ['label' => 'Danger Zone', 'route' => 'project.service.danger', 'icon' => 'shield-alert'],
        ])->filter(fn (array $item): bool => $item['visible'] ?? true)->map(fn (array $item): array => [
            ...$item,
            'active' => $currentRoute === $item['route']
                || ($item['route'] === 'project.service.scheduled-tasks.show'
                    && str($currentRoute)->startsWith('project.service.scheduled-tasks')),
        ]);

        $menuGroups = [
            'Settings' => ['General', 'Domains', 'Environment Variables', 'Persistent Storage'],
            'Observe & troubleshoot' => ['Runtime Logs', 'Terminal'],
            'Automation' => ['Scheduled Tasks', 'Webhooks', 'Backups'],
            'Operations' => ['Resource Operations', 'Tags', 'Danger Zone'],
        ];

        $groupedItems = collect($menuGroups)
            ->map(fn (array $labels) => collect($labels)
                ->map(fn (string $label) => $configurationItems->firstWhere('label', $label))
                ->filter()
                ->values())
            ->filter(fn ($items) => $items->isNotEmpty());

        $storageSections = $applications
            ->concat($databases)
            ->map(fn ($resource): array => [
                'id' => 'storage-service-'.$resource->uuid,
                'label' => Str::headline($resource->name),
            ]);
    @endphp

    <section class="application-settings-workspace mt-4 w-full max-w-[1180px] lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
            <aside class="application-settings-navigation min-w-0 xl:self-start">
                <nav aria-label="Service settings"
                    class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                    @foreach ($groupedItems as $groupLabel => $groupItems)
                        @unless ($loop->first)
                            <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]"
                                aria-hidden="true"></div>
                        @endunless
                        <div class="nav-section hidden xl:block">{{ $groupLabel }}</div>
                        @foreach ($groupItems as $menuItem)
                            <a @class([
                                'menu-item',
                                'menu-item-active' => $menuItem['active'],
                            ])
                                @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                                href="{{ route($menuItem['route'], $serviceRouteParameters) }}">
                                <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                                <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                                @if ($menuItem['hasWarning'] ?? false)
                                    <span class="ml-auto size-2 shrink-0 rounded-full bg-error" title="Required environment variables missing"></span>
                                @endif
                            </a>
                            @if ($menuItem['active'] && $menuItem['route'] === 'project.service.storages' && $storageSections->isNotEmpty())
                                <div class="nav-children hidden flex-col gap-0.5 py-1 xl:flex"
                                    x-data="{ activeSection: '' }">
                                    @foreach ($storageSections as $section)
                                        <button type="button" class="menu-subitem"
                                            :class="activeSection === '{{ $section['id'] }}' && 'menu-subitem-active'"
                                            @click="activeSection = '{{ $section['id'] }}'; window.scrollToSettingsSection?.('{{ $section['id'] }}')">
                                            <span class="menu-item-label truncate text-left"
                                                title="{{ $section['label'] }}">{{ $section['label'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    @endforeach
                </nav>
            </aside>

            <div class="min-w-0">
                @if ($currentRoute === 'project.service.configuration')
                    <livewire:project.service.stack-form :service="$service" />

                    <div class="mt-8" x-data="{
                        viewMode: localStorage.getItem('service-compose-resources-view') || 'table',
                        setViewMode(mode) {
                            this.viewMode = mode;
                            localStorage.setItem('service-compose-resources-view', mode);
                        }
                    }">
                        <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="text-base font-semibold text-black dark:text-fg">Compose resources</h2>
                                <p class="mt-1 text-sm text-neutral-500 dark:text-fg-dim">
                                    Applications and databases defined in this service.
                                </p>
                            </div>
                            <div class="flex w-full items-center justify-between gap-2 sm:w-auto sm:justify-start">
                                <div
                                    class="flex h-9 items-center rounded-lg border border-neutral-200 bg-white p-0.5 dark:border-white/[0.08] dark:bg-white/[0.06]">
                                    <button type="button" x-on:click="setViewMode('table')"
                                        class="flex size-7.5 items-center justify-center rounded-md transition-colors"
                                        :class="viewMode === 'table'
                                            ? 'control-selected'
                                            : 'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'"
                                        aria-label="Table view" title="Table view">
                                        <x-reicon name="unordered-list" class="size-3.5" />
                                    </button>
                                    <button type="button" x-on:click="setViewMode('grid')"
                                        class="flex size-7.5 items-center justify-center rounded-md transition-colors"
                                        :class="viewMode === 'grid'
                                            ? 'control-selected'
                                            : 'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'"
                                        aria-label="Grid view" title="Grid view">
                                        <x-reicon name="grid" class="size-3.5" />
                                    </button>
                                </div>
                                <a class="button" target="_blank" href="{{ $service->documentation() }}">
                                    Documentation
                                    <x-reicon name="external-link" class="size-4" />
                                </a>
                            </div>
                        </div>

                        <div :class="viewMode === 'grid'
                            ? 'grid grid-cols-1 gap-3 sm:grid-cols-2'
                            : 'overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]'">
                            @if ($applications->isNotEmpty() || $databases->isNotEmpty())
                                <div x-cloak x-show="viewMode === 'table'"
                                    class="grid grid-cols-[minmax(0,1fr)_auto] gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_8rem_5rem] dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                                    <div>Resource</div>
                                    <div class="hidden sm:block">Image</div>
                                    <div class="justify-self-start">Status</div>
                                    <div></div>
                                </div>
                            @endif

                            @if ($applications->isEmpty() && $databases->isEmpty())
                                <div
                                    class="application-settings-section overflow-hidden sm:col-span-2">
                                    <x-empty title="No compose resources"
                                        description="No applications or databases are defined in this Docker Compose file."
                                        icon-name="grid" />
                                </div>
                            @endif

                            @foreach ($applications as $application)
                                <livewire:project.service.resource-card :service="$service" :resource="$application"
                                    :parameters="$parameters"
                                    wire:key="service-application-card-{{ $application->id }}" />
                            @endforeach
                            @foreach ($databases as $database)
                                <livewire:project.service.resource-card :service="$service" :resource="$database"
                                    :parameters="$parameters"
                                    wire:key="service-database-card-{{ $database->id }}" />
                            @endforeach
                        </div>
                    </div>
                @elseif ($currentRoute === 'project.service.domains')
                    <livewire:project.service.domains :service="$service" />
                @elseif ($currentRoute === 'project.service.environment-variables')
                    <livewire:project.shared.environment-variable.all :resource="$service" />
                @elseif ($currentRoute === 'project.service.storages')
                    <div class="space-y-6">
                        <div
                            class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] leading-5 text-amber-800 dark:border-warning/15 dark:bg-warning/[0.07] dark:text-amber-300/90">
                            Service volume mounts are read-only here. Edit the Docker Compose file and reload it to change volumes.
                        </div>
                        @foreach ($applications as $application)
                            <livewire:project.service.storage wire:key="application-{{ $application->id }}"
                                :resource="$application" />
                        @endforeach
                        @foreach ($databases as $database)
                            <livewire:project.service.storage wire:key="database-{{ $database->id }}"
                                :resource="$database" />
                        @endforeach
                    </div>
                @elseif ($currentRoute === 'project.service.scheduled-tasks.show')
                    <livewire:project.shared.scheduled-task.all :resource="$service" />
                @elseif ($currentRoute === 'project.service.scheduled-tasks')
                    <livewire:project.shared.scheduled-task.show />
                @elseif ($currentRoute === 'project.service.webhooks')
                    <livewire:project.shared.webhooks :resource="$service" />
                @elseif ($currentRoute === 'project.service.resource-operations')
                    <livewire:project.shared.resource-operations :resource="$service" />
                @elseif ($currentRoute === 'project.service.tags')
                    <livewire:project.shared.tags :resource="$service" />
                @elseif ($currentRoute === 'project.service.danger')
                    <livewire:project.shared.danger :resource="$service" />
                @endif
            </div>
        </div>
    </section>
</div>
