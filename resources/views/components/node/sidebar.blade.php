@props(['node', 'activeMenu'])

@php
    $nodeRouteParameters = ['node_uuid' => $node->uuid];
    $nodeMenuItems = [
        [
            'label' => 'General',
            'route' => 'node.show',
            'active' => $activeMenu === 'general',
            'icon' => 'settings',
            'group' => 'Settings',
        ],
        [
            'label' => 'Internal DNS',
            'route' => 'node.internal-dns',
            'active' => $activeMenu === 'internal-dns',
            'icon' => 'network',
            'group' => 'Operations',
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
        ->groupBy('group');
@endphp

<aside class="application-settings-navigation min-w-0 xl:self-start">
    <nav aria-label="Node configuration sections"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        @foreach ($groupedNodeMenuItems as $groupLabel => $groupItems)
            @unless ($loop->first)
                <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]"
                    aria-hidden="true"></div>
            @endunless
            <div class="nav-section hidden xl:block">{{ $groupLabel }}</div>
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
                </a>
            @endforeach
        @endforeach
    </nav>
</aside>
