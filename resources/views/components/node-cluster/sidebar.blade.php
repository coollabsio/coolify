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
@endphp

<aside class="application-settings-navigation min-w-0 xl:self-start">
    <nav aria-label="Cluster configuration sections"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        @foreach ($groupedClusterMenuItems as $groupLabel => $groupItems)
            @unless ($loop->first)
                <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]"
                    aria-hidden="true"></div>
            @endunless
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
        @endforeach
    </nav>
</aside>
