@php
    $settingsMenuItems = [
        [
            'label' => 'General',
            'route' => 'settings.index',
            'active' => $activeMenu === 'general',
            'icon' => 'settings',
        ],
        [
            'label' => 'Advanced',
            'route' => 'settings.advanced',
            'active' => $activeMenu === 'advanced',
            'icon' => 'admin',
        ],
        [
            'label' => 'Updates',
            'route' => 'settings.updates',
            'active' => $activeMenu === 'updates',
            'icon' => 'dashboard',
        ],
    ];
@endphp

<aside class="application-settings-navigation min-w-0 xl:sticky xl:top-26 xl:self-start">
    <nav aria-label="Configuration sections"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-4 sm:grid-cols-3 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        <div class="nav-section hidden xl:block">Configuration</div>
        @foreach ($settingsMenuItems as $menuItem)
            <a @class([
                'menu-item',
                'menu-item-active' => $menuItem['active'],
            ])
                {{ wireNavigate() }} href="{{ route($menuItem['route']) }}">
                <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                <span class="menu-item-label">{{ $menuItem['label'] }}</span>
            </a>
        @endforeach
    </nav>
</aside>
