@props(['database', 'currentRoute'])

@php
    $databaseRouteParameters = [
        'project_uuid' => $database->environment->project->uuid,
        'environment_uuid' => $database->environment->uuid,
        'database_uuid' => $database->uuid,
    ];

    $configurationItems = collect([
        ['label' => 'General', 'route' => 'project.database.configuration', 'icon' => 'settings'],
        ['label' => 'Environment Variables', 'route' => 'project.database.environment-variables', 'icon' => 'variables'],
        ['label' => 'Persistent Storage', 'route' => 'project.database.persistent-storage', 'icon' => 'storages'],
        ['label' => 'Backups', 'route' => 'project.database.backup.index', 'icon' => 'database', 'visible' => $database->isBackupSolutionAvailable()],
        ['label' => 'Import Backup', 'route' => 'project.database.import-backup', 'icon' => 'upload', 'navigate' => false, 'visible' => auth()->user()?->can('update', $database)],
        ['label' => 'Servers', 'route' => 'project.database.servers', 'icon' => 'servers'],
        ['label' => 'Runtime Logs', 'route' => 'project.database.logs', 'icon' => 'unordered-list', 'navigate' => false],
        ['label' => 'Terminal', 'route' => 'project.database.command', 'icon' => 'browser-terminal', 'navigate' => false, 'visible' => auth()->user()?->can('canAccessTerminal')],
        ['label' => 'Webhooks', 'route' => 'project.database.webhooks', 'icon' => 'notifications'],
        ['label' => 'Healthcheck', 'route' => 'project.database.healthcheck', 'icon' => 'feedback'],
        ['label' => 'Resource Limits', 'route' => 'project.database.resource-limits', 'icon' => 'cpu'],
        ['label' => 'Resource Operations', 'route' => 'project.database.resource-operations', 'icon' => 'server-update'],
        ['label' => 'Metrics', 'route' => 'project.database.metrics', 'icon' => 'graph'],
        ['label' => 'Tags', 'route' => 'project.database.tags', 'icon' => 'tags'],
        ['label' => 'Danger Zone', 'route' => 'project.database.danger', 'icon' => 'shield-alert'],
    ])->filter(fn (array $item): bool => $item['visible'] ?? true)
        ->map(fn (array $item): array => [
            ...$item,
            'active' => $currentRoute === $item['route']
                || ($item['route'] === 'project.database.backup.index'
                    && str($currentRoute)->startsWith('project.database.backup')),
        ]);

    $menuGroups = [
        'Settings' => ['General', 'Environment Variables', 'Persistent Storage', 'Healthcheck'],
        'Observe & troubleshoot' => ['Runtime Logs', 'Terminal', 'Metrics'],
        'Deploy' => ['Servers'],
        'Automation' => ['Webhooks', 'Backups', 'Import Backup'],
        'Operations' => ['Resource Operations', 'Resource Limits', 'Tags', 'Danger Zone'],
    ];

    $groupedItems = collect($menuGroups)
        ->map(fn (array $labels) => collect($labels)
            ->map(fn (string $label) => $configurationItems->firstWhere('label', $label))
            ->filter()
            ->values())
        ->filter(fn ($items) => $items->isNotEmpty());

    $pageSections = $database->type() === 'standalone-postgresql'
        ? [
            ['id' => 'database-details-section', 'label' => 'Database details'],
            ['id' => 'credentials-section', 'label' => 'Credentials'],
            ['id' => 'initialization-section', 'label' => 'Initialization'],
            ['id' => 'runtime-network-section', 'label' => 'Runtime and network'],
            ['id' => 'public-access-section', 'label' => 'Public access'],
            ['id' => 'configuration-section', 'label' => 'Configuration'],
            ['id' => 'log-delivery-section', 'label' => 'Log delivery'],
            ['id' => 'initialization-scripts-section', 'label' => 'Initialization scripts'],
        ]
        : [];
@endphp

<aside class="application-settings-navigation min-w-0 xl:self-start">
    <nav aria-label="Database settings"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        @foreach ($groupedItems as $groupLabel => $groupItems)
            @unless ($loop->first)
                <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]" aria-hidden="true"></div>
            @endunless
            <div class="nav-section hidden xl:block">{{ $groupLabel }}</div>
            @foreach ($groupItems as $menuItem)
                <a @class(['menu-item', 'menu-item-active' => $menuItem['active']])
                    @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                    href="{{ route($menuItem['route'], $databaseRouteParameters) }}">
                    <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                    <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                </a>
                @if ($menuItem['active'] && $menuItem['route'] === 'project.database.configuration' && $pageSections !== [])
                    <div class="nav-children hidden flex-col gap-0.5 py-1 xl:flex"
                        x-data="{
                            activeSection: '',
                            scrollToSection(id) {
                                this.activeSection = id;
                                window.scrollToSettingsSection?.(id);
                            },
                        }">
                        @foreach ($pageSections as $section)
                            <button type="button" class="menu-subitem"
                                :class="activeSection === '{{ $section['id'] }}' && 'menu-subitem-active'"
                                @click="scrollToSection('{{ $section['id'] }}')">
                                <span class="menu-item-label text-left">{{ $section['label'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            @endforeach
        @endforeach
    </nav>
</aside>
