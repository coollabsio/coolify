@props([
    'project',
    'environment',
    'active' => 'architecture',
])

@php
    $items = [
        'architecture' => [
            'route' => route('railway.canvas', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
            'title' => 'Architecture',
            'icon' => 'architecture',
        ],
        'observability' => [
            'route' => route('railway.observability', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
            'title' => 'Observability',
            'icon' => 'chart',
        ],
        'logs' => [
            'route' => route('railway.logs', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
            'title' => 'Logs',
            'icon' => 'logs',
        ],
        'settings' => [
            'route' => route('railway.settings', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
            'title' => 'Settings',
            'icon' => 'settings',
        ],
    ];
@endphp

<nav class="flex flex-col items-center gap-1 w-14 shrink-0 py-3 border-r" style="border-color: var(--color-rw-border); background: var(--color-rw-surface);">
    @foreach ($items as $key => $item)
        <a href="{{ $item['route'] }}" wire:navigate title="{{ $item['title'] }}"
            class="rw-rail-item hover:rw-rail-item-hover {{ $active === $key ? 'rw-rail-item-active' : '' }} group relative">
            @switch($item['icon'])
                @case('architecture')
                    <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="16" y="16" width="6" height="6" rx="1"/><path d="M12 8v4M12 12H5v4M12 12h7v4"/></svg>
                    @break
                @case('chart')
                    <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="4" width="3" height="14"/></svg>
                    @break
                @case('logs')
                    <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3h11l5 5v13H4z"/><path d="M14 3v5h5M8 13h8M8 17h5"/></svg>
                    @break
                @case('settings')
                    <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            @endswitch
            <span class="rw-rail-tip absolute left-12 z-50 hidden group-hover:block whitespace-nowrap rounded-md px-2 py-1 text-[12px]"
                style="background: var(--color-rw-elevated); color: var(--color-rw-text); border: 1px solid var(--color-rw-border);">{{ $item['title'] }}</span>
        </a>
    @endforeach
</nav>
