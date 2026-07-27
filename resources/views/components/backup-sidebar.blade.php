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
            's3' => 'project.application.backup.s3',
            'retention' => 'project.application.backup.retention',
            'executions' => 'project.application.backup.executions',
            'danger' => 'project.application.backup.danger',
        ],
        'service' => [
            'back' => 'project.service.database.backups',
            'general' => 'project.service.database.backup.show',
            's3' => 'project.service.database.backup.s3',
            'retention' => 'project.service.database.backup.retention',
            'executions' => 'project.service.database.backup.executions',
            'danger' => 'project.service.database.backup.danger',
        ],
        default => [
            'back' => 'project.database.backup.index',
            'general' => 'project.database.backup.execution',
            's3' => 'project.database.backup.s3',
            'retention' => 'project.database.backup.retention',
            'executions' => 'project.database.backup.executions',
            'danger' => 'project.database.backup.danger',
        ],
    };

    $items = [
        ['key' => 'general', 'label' => 'General', 'icon' => 'settings'],
        ['key' => 's3', 'label' => 'S3 storage', 'icon' => 'storages'],
        ['key' => 'retention', 'label' => 'Retention', 'icon' => 'unordered-list'],
        ['key' => 'executions', 'label' => 'Executions', 'icon' => 'terminal'],
        ['key' => 'danger', 'label' => 'Danger Zone', 'icon' => 'admin'],
    ];
@endphp

<aside class="application-settings-navigation min-w-0 xl:sticky xl:top-26 xl:self-start">
    <nav aria-label="Backup settings"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-4 sm:grid-cols-3 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        <div class="nav-section hidden xl:block">Backup</div>
        <a class="menu-item" {{ wireNavigate() }} href="{{ route($routes['back'], $parameters) }}">
            <x-reicon name="logout" class="menu-item-icon rotate-180" />
            <span class="menu-item-label">Back to backups</span>
        </a>

        @foreach ($items as $item)
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
</aside>
