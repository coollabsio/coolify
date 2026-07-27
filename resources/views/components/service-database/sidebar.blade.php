@props([
    'parameters',
    'serviceDatabase',
    'isImportSupported' => false,
])

@php
    $items = [
        [
            'label' => 'General',
            'route' => 'project.service.index',
            'icon' => 'settings',
            'active' => request()->routeIs('project.service.index'),
        ],
        [
            'label' => 'Advanced',
            'route' => 'project.service.index.advanced',
            'icon' => 'grid',
            'active' => request()->routeIs('project.service.index.advanced'),
        ],
        [
            'label' => 'Backups',
            'route' => 'project.service.database.backups',
            'icon' => 'storages',
            'active' => request()->routeIs('project.service.database.backup*'),
            'visible' => $serviceDatabase?->isBackupSolutionAvailable() || $serviceDatabase?->is_migrated,
        ],
        [
            'label' => 'Import Backup',
            'route' => 'project.service.database.import',
            'icon' => 'storages',
            'active' => request()->routeIs('project.service.database.import'),
            'visible' => $isImportSupported,
            'navigate' => false,
        ],
    ];

    $items = array_values(array_filter($items, fn (array $item): bool => $item['visible'] ?? true));
@endphp

<aside class="application-settings-navigation min-w-0 xl:sticky xl:top-26 xl:self-start">
    <nav aria-label="Compose resource settings"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-4 sm:grid-cols-3 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        <div class="nav-section hidden xl:block">Compose resource</div>
        <a class="menu-item" {{ wireNavigate() }}
            href="{{ route('project.service.configuration', [...$parameters, 'stack_service_uuid' => null]) }}">
            <x-reicon name="logout" class="menu-item-icon rotate-180" />
            <span class="menu-item-label">Back to service</span>
        </a>

        @foreach ($items as $item)
            <a @class([
                'menu-item',
                'menu-item-active' => $item['active'],
            ])
                @if ($item['navigate'] ?? true) {{ wireNavigate() }} @endif
                href="{{ route($item['route'], $parameters) }}">
                <x-reicon :name="$item['icon']" class="menu-item-icon" />
                <span class="menu-item-label">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
</aside>
