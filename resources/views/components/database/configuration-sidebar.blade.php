@props(['database', 'currentRoute', 'navOnly' => false])

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
        ['label' => 'Files', 'route' => 'project.database.files', 'icon' => 'folder', 'visible' => auth()->user()?->can('canAccessTerminal')],
        ['label' => 'Webhooks', 'route' => 'project.database.webhooks', 'icon' => 'webhook'],
        ['label' => 'Healthcheck', 'route' => 'project.database.healthcheck', 'icon' => 'feedback'],
        ['label' => 'Resource Limits', 'route' => 'project.database.resource-limits', 'icon' => 'cpu'],
        ['label' => 'Resource Operations', 'route' => 'project.database.resource-operations', 'icon' => 'server-update'],
        ['label' => 'Metrics', 'route' => 'project.database.metrics', 'icon' => 'graph'],
        ['label' => 'Tags', 'route' => 'project.database.tags', 'icon' => 'tags'],
        ['label' => 'Danger Zone', 'route' => 'project.database.danger', 'icon' => 'danger'],
    ])->filter(fn (array $item): bool => $item['visible'] ?? true)
        ->map(fn (array $item): array => [
            ...$item,
            'active' => $currentRoute === $item['route']
                || ($item['route'] === 'project.database.backup.index'
                    && str($currentRoute)->startsWith('project.database.backup')),
        ]);

    $menuGroups = [
        'Settings' => ['General', 'Environment Variables', 'Persistent Storage', 'Healthcheck'],
        'Observe & troubleshoot' => ['Runtime Logs', 'Terminal', 'Files', 'Metrics'],
        'Deploy' => ['Servers'],
        'Automation' => ['Webhooks', 'Backups', 'Import Backup'],
        'Operations' => ['Resource Operations', 'Resource Limits', 'Tags'],
        'Danger Zone' => ['Danger Zone'],
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

@php
    // The main sidebar shows this menu on wide screens (see components/navbar.blade.php).
    registerMainNavContext(0, 'Dashboard', 'Back to the main menu');
@endphp

{{-- Rendered twice: in the page (below xl), and teleported into the main sidebar (xl and up).
     navOnly renders only the sidebar copy, as the parent menu of a deeper page (e.g. a backup). --}}
@foreach ($navOnly ? ['main-nav'] : ['page', 'main-nav'] as $placement)
@if ($placement === 'main-nav')
@teleport('#main-nav-context-0')
<div class="nav-context-menu">
@else
<aside class="application-settings-navigation in-main-nav min-w-0 xl:self-start"
    data-settings-search-items="{{ json_encode(settingsSearchItems($groupedItems, $databaseRouteParameters, ['project.database.configuration' => $pageSections])) }}">
@endif
    <nav aria-label="Database settings"
        @class([
            'grid gap-0.5',
            'grid-cols-2 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]' => $placement === 'page',
            'grid-cols-1' => $placement === 'main-nav',
        ])>
        @foreach ($groupedItems as $groupLabel => $groupItems)
            @unless ($loop->first)
                <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]" aria-hidden="true"></div>
            @endunless
            @foreach ($groupItems as $menuItem)
                <a @class(['menu-item', 'menu-item-active' => $menuItem['active']])
                    @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                    href="{{ route($menuItem['route'], $databaseRouteParameters) }}">
                    <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                    <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                    @if ($menuItem['label'] === 'Files')
                        <x-beta-badge class="ml-auto shrink-0" />
                    @endif
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
@if ($placement === 'main-nav')
</div>
@endteleport
@else
</aside>
@endif
@endforeach
