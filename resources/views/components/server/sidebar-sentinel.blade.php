@php
    $sentinelMenuItems = [
        [
            'label' => 'Configuration',
            'route' => 'server.sentinel',
            'active' => request()->routeIs('server.sentinel'),
            'icon' => 'settings',
        ],
        [
            'label' => 'Logs',
            'route' => 'server.sentinel.logs',
            'active' => request()->routeIs('server.sentinel.logs'),
            'icon' => 'file-content',
        ],
    ];
@endphp

<aside class="application-settings-navigation min-w-0 xl:sticky xl:top-26 xl:self-start">
    @can('viewSentinel', $server)
        <nav aria-label="Sentinel sections"
            class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
            <div class="nav-section hidden xl:block">Sentinel</div>
            @foreach ($sentinelMenuItems as $menuItem)
                <a wire:key="server-sentinel-link-{{ str($menuItem['label'])->slug() }}"
                    @class([
                        'menu-item',
                        'menu-item-active' => $menuItem['active'],
                    ])
                    {{ wireNavigate() }}
                    href="{{ route($menuItem['route'], $parameters) }}">
                    <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                    <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                </a>
            @endforeach
        </nav>
    @endcan
</aside>
