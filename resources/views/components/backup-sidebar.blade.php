@props([
    'context',
    'parameters',
    'section',
])

@php
    $routes = match ($context) {
        'application' => [
            'back' => 'project.application.backup.index',
            'general' => 'project.application.backup.show',
            'executions' => 'project.application.backup.executions',
            'danger' => 'project.application.backup.danger',
        ],
        'service' => [
            'back' => 'project.service.volume-backups.index',
            'general' => 'project.service.database.backup.show',
            'executions' => 'project.service.database.backup.executions',
            'danger' => 'project.service.database.backup.danger',
        ],
        'service-volume' => [
            'back' => 'project.service.volume-backups.index',
            'general' => 'project.service.volume-backups.show',
            'executions' => 'project.service.volume-backups.executions',
            'danger' => 'project.service.volume-backups.danger',
        ],
        default => [
            'back' => 'project.database.configuration',
            'general' => 'project.database.backup.execution',
            'executions' => 'project.database.backup.executions',
            'danger' => 'project.database.backup.danger',
        ],
    };

    $items = [
        ['key' => 'general', 'label' => 'General', 'icon' => 'settings'],
        ['key' => 'executions', 'label' => 'Executions', 'icon' => 'browser-terminal'],
        ['key' => 'danger', 'label' => 'Danger Zone', 'icon' => 'danger'],
    ];
    $backLabel = $context === 'database' ? 'Back to database' : 'Back to backups';
    // The resource menu that the page renders one Back away in the main sidebar.
    $parentMenu = match ($context) {
        'application' => 'Application',
        'database' => 'Database',
        default => 'Service',
    };
    $backParameters = match ($context) {
        'database' => collect($parameters)->except('backup_uuid')->all(),
        'service' => collect($parameters)->except(['stack_service_uuid', 'backup_uuid'])->all(),
        default => $parameters,
    };
@endphp

<x-main-nav-menu :depth="1" :back-label="$parentMenu" :back-title="'Back to the '.strtolower($parentMenu).' menu'"
    data-settings-search-items="{{ json_encode(settingsSearchItems(['Backup' => collect($items)->map(fn (array $item): array => [...$item, 'route' => $routes[$item['key']]])], $parameters)) }}">
    <nav aria-label="Backup settings"
        class="grid grid-cols-1 gap-0.5">
        <a class="menu-item" data-page-only {{ wireNavigate() }} href="{{ route($routes['back'], $backParameters) }}">
            <x-reicon name="logout" class="menu-item-icon rotate-180" />
            <span class="menu-item-label">{{ $backLabel }}</span>
        </a>

        @foreach ($items as $item)
            @if ($item['key'] === 'danger')
                <div class="col-span-full my-2 border-t border-neutral-200 dark:border-white/[0.06]" aria-hidden="true"></div>
            @endif
            <a @class([
                'menu-item',
                'menu-item-active' => $section === $item['key'],
            ])
                {{ wireNavigate() }} href="{{ route($routes[$item['key']], $parameters) }}">
                <x-reicon :name="$item['icon']" class="menu-item-icon" />
                <span class="menu-item-label">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
</x-main-nav-menu>
