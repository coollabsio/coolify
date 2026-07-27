@php
    $securityMenuItems = [
        [
            'label' => 'Server Patching',
            'route' => 'server.security.patches',
            'active' => request()->routeIs('server.security.patches'),
            'icon' => 'admin',
        ],
        [
            'label' => 'Terminal Access',
            'route' => 'server.security.terminal-access',
            'active' => request()->routeIs('server.security.terminal-access'),
            'icon' => 'terminal',
            'navigate' => false,
        ],
    ];
@endphp

<aside class="application-settings-navigation min-w-0 xl:sticky xl:top-26 xl:self-start">
    <nav aria-label="Server security sections"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        <div class="nav-section hidden xl:block">Security</div>
        @foreach ($securityMenuItems as $menuItem)
            <a wire:key="server-security-link-{{ str($menuItem['label'])->slug() }}"
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
