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

    $pageSections = array_values(array_filter([
        ['id' => 'database-details-section', 'label' => 'Database details'],
        $database->type() === 'standalone-sqlite' ? null : ['id' => 'access-section', 'label' => 'Access'],
        ['id' => 'container-section', 'label' => 'Container'],
        $database->type() === 'standalone-postgresql'
            ? ['id' => 'initialization-scripts-section', 'label' => 'Initialization scripts']
            : null,
    ]));
@endphp

@php
    // The main sidebar and the mobile drawer show this menu (see components/navbar.blade.php).
    registerMainNavContext(0, 'Dashboard', 'Back to the main menu');
@endphp

{{-- Rendered twice: in the page (CSS hides it; it keeps the command palette items), and teleported into the main sidebar.
     navOnly renders only the sidebar copy, as the parent menu of a deeper page (e.g. a backup). --}}
@foreach ($navOnly ? ['main-nav'] : ['page', 'main-nav'] as $placement)
@if ($placement === 'main-nav')
@teleport('#main-nav-context-0')
<div class="nav-context-menu">
@else
<aside class="application-settings-navigation in-main-nav min-w-0"
    data-settings-search-items="{{ json_encode(settingsSearchItems($groupedItems, $databaseRouteParameters, ['project.database.configuration' => $pageSections])) }}">
@endif
    <nav aria-label="Database settings"
        class="grid grid-cols-1 gap-0.5">
        @foreach ($groupedItems as $groupLabel => $groupItems)
            @unless ($loop->first)
                <div class="my-2 border-t border-neutral-200 dark:border-white/[0.06]" aria-hidden="true"></div>
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
                    <div class="nav-children flex flex-col gap-0.5 py-1"
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
