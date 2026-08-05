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
        ['label' => 'Servers', 'route' => 'project.database.servers', 'icon' => 'servers'],
        ['label' => 'Runtime', 'route' => 'project.database.logs', 'icon' => 'unordered-list', 'navigate' => false],
        ['label' => 'Terminal', 'route' => 'project.database.command', 'icon' => 'browser-terminal', 'navigate' => false, 'visible' => auth()->user()?->can('canAccessTerminal')],
        ['label' => 'Import Backup', 'route' => 'project.database.import-backup', 'icon' => 'upload', 'visible' => auth()->user()?->can('update', $database)],
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
        'Settings' => ['General', 'Environment Variables', 'Persistent Storage', 'Backups', 'Servers', 'Import Backup'],
        'Automation' => ['Webhooks', 'Healthcheck'],
        'Logs' => ['Runtime'],
        'Operations' => ['Terminal', 'Resource Limits', 'Resource Operations', 'Metrics', 'Tags', 'Danger Zone'],
    ];

    $groupedItems = collect($menuGroups)
        ->map(fn (array $labels) => $configurationItems->whereIn('label', $labels)->values())
        ->filter(fn ($items) => $items->isNotEmpty());
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
            @endforeach
        @endforeach
    </nav>
</aside>
