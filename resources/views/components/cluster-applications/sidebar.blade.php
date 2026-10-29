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
            'key' => 'domains',
            'label' => 'Domains',
            'route' => 'project.cluster-application.domains',
            'icon' => 'globe',
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
            'key' => 'servers',
            'label' => 'Servers',
            'route' => 'project.cluster-application.servers',
            'icon' => 'servers',
            'group' => 'Deploy',
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
            'icon' => 'danger',
            'group' => 'Danger Zone',
        ],
    ]);
    $groupedMenuItems = $menuItems->groupBy('group');
    // In-page sections (cards) shown as sub-items under the active page, like the v4 application sidebar.
    $pageSections = [
        'general' => [
            ['id' => 'cluster-application-details', 'label' => 'Application details'],
            ['id' => 'cluster-application-access', 'label' => 'Access'],
            ['id' => 'cluster-application-build', 'label' => 'Build & deploy'],
            ['id' => 'cluster-application-container', 'label' => 'Container'],
        ],
        'resource-limits' => [
            ['id' => 'cluster-application-cpu-limits', 'label' => 'CPU'],
            ['id' => 'cluster-application-memory-limits', 'label' => 'Memory'],
        ],
    ];
@endphp

<x-main-nav-menu back-label="Dashboard">
    <nav aria-label="Cluster application sections"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        @foreach ($groupedMenuItems as $groupLabel => $groupItems)
            @unless ($loop->first)
                <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]"
                    aria-hidden="true"></div>
            @endunless
            @foreach ($groupItems as $menuItem)
                <a @class([
                        'menu-item',
                        'menu-item-active' => $menuItem['key'] === $activeKey,
                    ])
                    @if ($menuItem['key'] === $activeKey) aria-current="page" @endif
                    {{ wireNavigate() }}
                    href="{{ route($menuItem['route'], $routeParameters) }}">
                    <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                    <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                </a>
                @if ($menuItem['key'] === $section && filled($pageSections[$section] ?? null))
                    <div class="nav-children hidden flex-col gap-0.5 py-1 xl:flex" x-data="{ activeSection: '' }">
                        @foreach ($pageSections[$section] as $pageSection)
                            <button type="button" class="menu-subitem"
                                :class="activeSection === '{{ $pageSection['id'] }}' && 'menu-subitem-active'"
                                x-on:click="activeSection = '{{ $pageSection['id'] }}'; history.replaceState(null, '', '#{{ $pageSection['id'] }}'); window.scrollToSettingsSection?.('{{ $pageSection['id'] }}')">
                                <span class="menu-item-label text-left">{{ $pageSection['label'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            @endforeach
        @endforeach
    </nav>
</x-main-nav-menu>
