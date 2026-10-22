@props(['node', 'activeMenu'])

@php
    $nodeRouteParameters = ['node_uuid' => $node->uuid];
    $sentinelNeedsAttention = data_get(\Illuminate\Support\Facades\Cache::get($node->cacheKey()), 'status') !== 'connected'
        || ! $node->is_usable;
    $nodeMenuItems = [
        [
            'label' => 'General',
            'route' => 'node.show',
            'active' => $activeMenu === 'general',
            'icon' => 'settings',
            'group' => 'Settings',
        ],
        [
            'label' => 'Applications',
            'route' => 'node.workloads',
            'active' => $activeMenu === 'workloads',
            'icon' => 'layers',
            'group' => 'Applications',
        ],
        [
            'label' => 'Containers',
            'route' => 'node.containers',
            'active' => $activeMenu === 'containers',
            'icon' => 'grid',
            'group' => 'Applications',
        ],
        [
            'label' => 'Internal DNS',
            'route' => 'node.internal-dns',
            'active' => $activeMenu === 'internal-dns',
            'icon' => 'network',
            'group' => 'Networking',
        ],
        [
            'label' => 'Sentinel',
            'route' => 'node.sentinel',
            'active' => $activeMenu === 'sentinel',
            'icon' => 'shield-star',
            'group' => 'Operations',
            'warning' => $sentinelNeedsAttention,
        ],
        [
            'label' => 'Activity',
            'route' => 'node.activity',
            'active' => $activeMenu === 'activity',
            'icon' => 'time-back',
            'group' => 'Operations',
        ],
        [
            'label' => 'Logs',
            'route' => 'node.logs',
            'active' => $activeMenu === 'logs',
            'icon' => 'file-content',
            'group' => 'Operations',
            'visible' => auth()->user()?->can('manageSentinel', $node),
        ],
        [
            'label' => 'Terminal',
            'route' => 'node.command',
            'active' => $activeMenu === 'terminal',
            'icon' => 'browser-terminal',
            'group' => 'Operations',
            'navigate' => false,
            'visible' => auth()->user()?->can('canAccessTerminal'),
        ],
    ];

    $groupedNodeMenuItems = collect($nodeMenuItems)
        ->filter(fn (array $item): bool => $item['visible'] ?? true)
        ->values()
        ->groupBy('group');
@endphp

<x-main-nav-menu back-label="Servers">
    <nav aria-label="Server configuration sections"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        @foreach ($groupedNodeMenuItems as $groupLabel => $groupItems)
            @unless ($loop->first)
                <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]"
                    aria-hidden="true"></div>
            @endunless
            @foreach ($groupItems as $menuItem)
                <a wire:key="node-settings-link-{{ str($menuItem['label'])->slug() }}"
                    @class([
                        'menu-item',
                        'menu-item-active' => $menuItem['active'],
                    ])
                    @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                    href="{{ route($menuItem['route'], $nodeRouteParameters) }}">
                    <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                    <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                    @if ($menuItem['warning'] ?? false)
                        <x-reicon name="alert-triangle" title="Sentinel needs attention"
                            class="ml-auto size-3.5 shrink-0 text-orange-500 dark:text-warning" />
                    @endif
                </a>
            @endforeach
        @endforeach
    </nav>
</x-main-nav-menu>
