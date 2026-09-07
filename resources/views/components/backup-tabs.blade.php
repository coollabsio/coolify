@props([
    'context',
    'parameters',
    'section',
])

@php
    $routes = match ($context) {
        'service-schedule' => [
            'general' => true,
            's3' => true,
            'retention' => true,
            'danger' => true,
        ],
        'service' => [
            'general' => 'project.service.database.backup.show',
            's3' => 'project.service.database.backup.s3',
            'retention' => 'project.service.database.backup.retention',
            'executions' => 'project.service.database.backup.executions',
            'danger' => 'project.service.database.backup.danger',
        ],
        'service-volume' => [
            'general' => 'project.service.volume-backups.show',
            's3' => 'project.service.volume-backups.s3',
            'retention' => 'project.service.volume-backups.retention',
            'executions' => 'project.service.volume-backups.executions',
            'danger' => 'project.service.volume-backups.danger',
        ],
    };

    $items = collect([
        ['key' => 'general', 'label' => 'General'],
        ['key' => 's3', 'label' => 'S3 storage'],
        ['key' => 'retention', 'label' => 'Retention'],
        ['key' => 'executions', 'label' => 'Executions'],
        ['key' => 'danger', 'label' => 'Danger Zone'],
    ])->filter(fn (array $item): bool => isset($routes[$item['key']]));
@endphp

<nav aria-label="Backup sections"
    class="flex min-w-0 flex-wrap gap-1 border-b border-neutral-200 pb-2 dark:border-white/[0.08]">
    @foreach ($items as $item)
        @if ($context === 'service-schedule')
            <button type="button" @click="activeSection = '{{ $item['key'] }}'"
                :class="activeSection === '{{ $item['key'] }}'
                    ? 'bg-coollabs/10 text-coollabs ring-1 ring-coollabs/25 dark:bg-warning/15 dark:text-warning dark:ring-warning/25'
                    : 'text-neutral-500 hover:bg-black/5 hover:text-neutral-900 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg'"
                class="inline-flex h-8 shrink-0 cursor-pointer items-center rounded-md px-3 text-[13px] font-medium transition-colors">
                {{ $item['label'] }}
            </button>
        @else
            <a @class([
                'inline-flex h-8 shrink-0 items-center rounded-md px-3 text-[13px] font-medium transition-colors',
                'bg-coollabs/10 text-coollabs ring-1 ring-coollabs/25 dark:bg-warning/15 dark:text-warning dark:ring-warning/25' => $section === $item['key'],
                'text-neutral-500 hover:bg-black/5 hover:text-neutral-900 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg' => $section !== $item['key'],
            ])
                {{ wireNavigate() }} href="{{ route($routes[$item['key']], $parameters) }}">
                {{ $item['label'] }}
            </a>
        @endif
    @endforeach
</nav>
