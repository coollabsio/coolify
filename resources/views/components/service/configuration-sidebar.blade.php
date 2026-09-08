@props(['service', 'currentRoute'])

@php
    $serviceRouteParameters = [
        'project_uuid' => $service->environment->project->uuid,
        'environment_uuid' => $service->environment->uuid,
        'service_uuid' => $service->uuid,
    ];

    $configurationItems = collect([
        ['label' => 'General', 'route' => 'project.service.configuration', 'icon' => 'settings'],
        ['label' => 'Domains', 'route' => 'project.service.domains', 'icon' => 'globe'],
        ['label' => 'Environment Variables', 'route' => 'project.service.environment-variables', 'icon' => 'variables', 'hasWarning' => ! $service->isDeployable],
        ['label' => 'Persistent Storage', 'route' => 'project.service.storages', 'icon' => 'storages'],
        ['label' => 'Backups', 'route' => 'project.service.volume-backups.index', 'icon' => 'database'],
        ['label' => 'Import Backup', 'route' => 'project.service.import-backup', 'icon' => 'upload', 'navigate' => false],
        ['label' => 'Runtime Logs', 'route' => 'project.service.logs', 'icon' => 'unordered-list', 'navigate' => false],
        ['label' => 'Terminal', 'route' => 'project.service.command', 'icon' => 'browser-terminal', 'navigate' => false, 'visible' => auth()->user()?->can('canAccessTerminal')],
        ['label' => 'Scheduled Tasks', 'route' => 'project.service.scheduled-tasks.show', 'icon' => 'calendar'],
        ['label' => 'Webhooks', 'route' => 'project.service.webhooks', 'icon' => 'notifications'],
        ['label' => 'Resource Operations', 'route' => 'project.service.resource-operations', 'icon' => 'server-update'],
        ['label' => 'Tags', 'route' => 'project.service.tags', 'icon' => 'tags'],
        ['label' => 'Danger Zone', 'route' => 'project.service.danger', 'icon' => 'shield-alert'],
    ])->filter(fn (array $item): bool => $item['visible'] ?? true)
        ->map(fn (array $item): array => [
            ...$item,
            'active' => $currentRoute === $item['route']
                || ($item['route'] === 'project.service.scheduled-tasks.show'
                    && str($currentRoute)->startsWith('project.service.scheduled-tasks'))
                || ($item['route'] === 'project.service.volume-backups.index'
                    && str($currentRoute)->startsWith('project.service.volume-backups'))
                || ($item['route'] === 'project.service.import-backup'
                    && str($currentRoute)->startsWith('project.service.import-backup')),
        ]);

    $menuGroups = [
        'Settings' => ['General', 'Domains', 'Environment Variables', 'Persistent Storage'],
        'Observe & troubleshoot' => ['Runtime Logs', 'Terminal'],
        'Automation' => ['Scheduled Tasks', 'Webhooks', 'Backups', 'Import Backup'],
        'Operations' => ['Resource Operations', 'Tags', 'Danger Zone'],
    ];

    $groupedItems = collect($menuGroups)
        ->map(fn (array $labels) => collect($labels)
            ->map(fn (string $label) => $configurationItems->firstWhere('label', $label))
            ->filter()
            ->values())
        ->filter(fn ($items) => $items->isNotEmpty());
@endphp

<aside class="application-settings-navigation min-w-0 xl:self-start">
    <nav aria-label="Service settings"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        @foreach ($groupedItems as $groupLabel => $groupItems)
            @unless ($loop->first)
                <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]" aria-hidden="true"></div>
            @endunless
            <div class="nav-section hidden xl:block">{{ $groupLabel }}</div>
            @foreach ($groupItems as $menuItem)
                <a @class(['menu-item', 'menu-item-active' => $menuItem['active']])
                    @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                    href="{{ route($menuItem['route'], $serviceRouteParameters) }}">
                    <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                    <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                    @if ($menuItem['hasWarning'] ?? false)
                        <span class="ml-auto size-2 shrink-0 rounded-full bg-error" title="Required environment variables missing"></span>
                    @endif
                </a>
            @endforeach
        @endforeach
    </nav>
</aside>
