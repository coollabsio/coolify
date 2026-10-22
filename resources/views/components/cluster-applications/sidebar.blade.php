@props(['section', 'routeParameters'])

@php
    $activeKey = $section === 'deployment' ? 'deployments' : $section;
    $menuItems = collect([
        [
            'key' => 'general',
            'label' => 'General',
            'route' => 'project.cluster-application.show',
            'icon' => 'settings',
            'group' => 'Settings',
        ],
        [
            'key' => 'environment-variables',
            'label' => 'Environment Variables',
            'route' => 'project.cluster-application.environment-variables',
            'icon' => 'variables',
            'group' => 'Settings',
        ],
        [
            'key' => 'logs',
            'label' => 'Runtime Logs',
            'route' => 'project.cluster-application.logs',
            'icon' => 'unordered-list',
            'group' => 'Observe & troubleshoot',
        ],
        [
            'key' => 'deployments',
            'label' => 'Deployment Logs',
            'route' => 'project.cluster-application.deployments',
            'icon' => 'time-back',
            'group' => 'Observe & troubleshoot',
        ],
        [
            'key' => 'resource-limits',
            'label' => 'Resource Limits',
            'route' => 'project.cluster-application.resource-limits',
            'icon' => 'cpu',
            'group' => 'Operations',
        ],
        [
            'key' => 'danger',
            'label' => 'Danger Zone',
            'route' => 'project.cluster-application.danger',
            'icon' => 'shield-alert',
            'group' => 'Operations',
        ],
    ]);
    $groupedMenuItems = $menuItems->groupBy('group');
@endphp

<aside class="application-settings-navigation min-w-0 xl:self-start">
    <nav aria-label="Cluster application sections"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        @foreach ($groupedMenuItems as $groupLabel => $groupItems)
            @unless ($loop->first)
                <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]"
                    aria-hidden="true"></div>
            @endunless
            @foreach ($groupItems as $menuItem)
                <a wire:key="cluster-application-link-{{ $menuItem['key'] }}"
                    @class([
                        'menu-item',
                        'menu-item-active' => $menuItem['key'] === $activeKey,
                    ])
                    @if ($menuItem['key'] === $activeKey) aria-current="page" @endif
                    {{ wireNavigate() }}
                    href="{{ route($menuItem['route'], $routeParameters) }}">
                    <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                    <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                </a>
            @endforeach
        @endforeach
    </nav>
</aside>
