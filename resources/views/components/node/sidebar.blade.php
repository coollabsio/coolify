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
            'label' => 'Workloads',
            'route' => 'node.workloads',
            'active' => $activeMenu === 'workloads',
            'icon' => 'layers',
            'group' => 'Workloads',
        ],
        [
            'label' => 'Containers',
            'route' => 'node.containers',
            'active' => $activeMenu === 'containers',
            'icon' => 'grid',
            'group' => 'Workloads',
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

    // Only the group that holds the current page is expanded by default.
    $activeGroup = (string) $groupedNodeMenuItems->search(
        fn ($items) => $items->contains(fn ($item) => $item['active'] ?? false)
    );
@endphp

<aside class="application-settings-navigation min-w-0 xl:self-start">
    <nav aria-label="Node configuration sections"
        x-data="settingsSidebarAccordion({ activeGroup: @js($activeGroup), storageKey: 'coolify.settings-sidebar.node' })"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        @foreach ($groupedNodeMenuItems as $groupLabel => $groupItems)
            @unless ($loop->first)
                <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]"
                    aria-hidden="true"></div>
            @endunless
            <button type="button" class="nav-section-toggle hidden xl:flex" @click="toggle(@js($groupLabel))"
                :aria-expanded="isOpen(@js($groupLabel))">
                <span>{{ $groupLabel }}</span>
                <svg class="size-3 shrink-0 opacity-60 transition-transform"
                    :class="!isOpen(@js($groupLabel)) && '-rotate-90'" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                </svg>
            </button>
            <div class="contents" :class="isOpen(@js($groupLabel)) ? 'xl:block' : 'xl:hidden'">
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
            </div>
        @endforeach
    </nav>
</aside>
