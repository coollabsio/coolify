@php
    $proxyMenuItems = [
        [
            'label' => 'Configuration',
            'route' => 'server.proxy',
            'active' => request()->routeIs('server.proxy'),
            'icon' => 'settings',
        ],
        [
            'label' => 'Dynamic Configurations',
            'route' => 'server.proxy.dynamic-confs',
            'active' => request()->routeIs('server.proxy.dynamic-confs'),
            'icon' => 'sliders',
            'visible' => $server->proxySet(),
        ],
        [
            'label' => 'Logs',
            'route' => 'server.proxy.logs',
            'active' => request()->routeIs('server.proxy.logs'),
            'icon' => 'file-content',
            'visible' => $server->proxySet(),
            'navigate' => false,
        ],
    ];

    $proxyMenuItems = array_values(array_filter(
        $proxyMenuItems,
        fn (array $item): bool => $item['visible'] ?? true,
    ));
@endphp

<aside class="application-settings-navigation min-w-0 xl:sticky xl:top-26 xl:self-start">
    <nav aria-label="Proxy sections"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-4 sm:grid-cols-3 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        <div class="nav-section hidden xl:block">Proxy</div>
        @foreach ($proxyMenuItems as $menuItem)
            <a wire:key="server-proxy-link-{{ str($menuItem['label'])->slug() }}"
                @class([
                    'menu-item',
                    'menu-item-active' => $menuItem['active'],
                ])
                @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                href="{{ route($menuItem['route'], $parameters) }}">
                <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                <span class="menu-item-label">{{ $menuItem['label'] }}</span>
            </a>
        @endforeach
    </nav>
</aside>
