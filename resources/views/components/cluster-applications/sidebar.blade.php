@props(['section', 'routeParameters'])

@php
    $menuItems = collect([
        [
            'key' => 'general',
            'label' => 'General',
            'route' => 'project.cluster-application.show',
            'icon' => 'settings',
            'group' => 'Settings',
        ],
        [
            'key' => 'configuration',
            'label' => 'Configuration',
            'route' => 'project.cluster-application.configuration',
            'icon' => 'code',
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
            'key' => 'resource-limits',
            'label' => 'Resource Limits',
            'route' => 'project.cluster-application.resource-limits',
            'icon' => 'cpu',
            'group' => 'Settings',
        ],
        [
            'key' => 'deployments',
            'label' => 'Deployments',
            'route' => 'project.cluster-application.deployments',
            'icon' => 'time-back',
            'group' => 'Operations',
        ],
    ]);
    $groupedMenuItems = $menuItems->groupBy('group');
    $activeGroup = (string) $groupedMenuItems->search(
        fn ($items) => $items->contains(fn (array $item): bool => $item['key'] === $section)
    );
@endphp

<aside class="application-settings-navigation min-w-0 xl:self-start">
    <nav aria-label="Cluster application sections"
        x-data="settingsSidebarAccordion({ activeGroup: @js($activeGroup), storageKey: 'coolify.settings-sidebar.cluster-application' })"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        @foreach ($groupedMenuItems as $groupLabel => $groupItems)
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
                    <a wire:key="cluster-application-link-{{ $menuItem['key'] }}"
                        @class([
                            'menu-item',
                            'menu-item-active' => $menuItem['key'] === $section,
                        ])
                        @if ($menuItem['key'] === $section) aria-current="page" @endif
                        {{ wireNavigate() }}
                        href="{{ route($menuItem['route'], $routeParameters) }}">
                        <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                        <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>
</aside>
