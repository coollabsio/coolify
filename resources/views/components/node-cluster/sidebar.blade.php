@props(['cluster', 'activeMenu', 'nodesNeedAttention' => false])

@php
    $clusterRouteParameters = ['cluster_uuid' => $cluster->uuid];
    $clusterMenuItems = collect([
        [
            'label' => 'General',
            'route' => 'node-cluster.show',
            'active' => $activeMenu === 'general',
            'icon' => 'settings',
            'group' => 'Settings',
        ],
        [
            'label' => 'Advanced',
            'route' => 'node-cluster.advanced',
            'active' => $activeMenu === 'advanced',
            'icon' => 'grid',
            'group' => 'Settings',
        ],
        [
            'label' => 'Resources',
            'route' => 'node-cluster.resources',
            'active' => $activeMenu === 'resources',
            'icon' => 'projects',
            'group' => 'Cluster',
        ],
        [
            'label' => 'Servers',
            'route' => 'node-cluster.nodes',
            'active' => $activeMenu === 'nodes',
            'icon' => 'servers',
            'group' => 'Network',
            'warning' => $nodesNeedAttention,
        ],
        [
            'label' => 'Firewall',
            'route' => 'node-cluster.firewall',
            'active' => $activeMenu === 'firewall',
            'icon' => 'shield-star',
            'group' => 'Network',
        ],
        [
            'label' => 'Danger',
            'route' => 'node-cluster.delete',
            'active' => $activeMenu === 'danger',
            'icon' => 'shield-alert',
            'group' => 'Danger zone',
            'visible' => auth()->user()?->can('delete', $cluster),
        ],
    ])
        ->filter(fn (array $item): bool => $item['visible'] ?? true)
        ->values();
    $groupedClusterMenuItems = $clusterMenuItems->groupBy('group');
    $activeGroup = (string) $groupedClusterMenuItems->search(
        fn ($items) => $items->contains(fn ($item) => $item['active'])
    );
@endphp

<aside class="application-settings-navigation min-w-0 xl:self-start">
    <nav aria-label="Cluster configuration sections"
        x-data="settingsSidebarAccordion({ activeGroup: @js($activeGroup), storageKey: 'coolify.settings-sidebar.node-cluster' })"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        @foreach ($groupedClusterMenuItems as $groupLabel => $groupItems)
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
                    <a wire:key="node-cluster-settings-link-{{ str($menuItem['label'])->slug() }}"
                        @class([
                            'menu-item',
                            'menu-item-active' => $menuItem['active'],
                        ])
                        {{ wireNavigate() }}
                        href="{{ route($menuItem['route'], $clusterRouteParameters) }}">
                        <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                        <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                        @if ($menuItem['warning'] ?? false)
                            <x-reicon name="alert-triangle" data-testid="node-cluster-nodes-warning"
                                class="ml-auto size-3.5 shrink-0 text-orange-500 dark:text-warning" />
                        @endif
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>
</aside>
