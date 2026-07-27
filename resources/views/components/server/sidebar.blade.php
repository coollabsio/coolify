@php
    $serverRouteParameters = ['server_uuid' => $server->uuid];
    $serverMenuItems = [
        [
            'label' => 'General',
            'route' => 'server.show',
            'active' => $activeMenu === 'general',
            'icon' => 'settings',
            'group' => 'Settings',
        ],
        [
            'label' => 'Advanced',
            'route' => 'server.advanced',
            'active' => $activeMenu === 'advanced',
            'icon' => 'grid',
            'group' => 'Settings',
            'visible' => $server->isFunctional(),
        ],
        [
            'label' => 'Private Key',
            'route' => 'server.private-key',
            'active' => $activeMenu === 'private-key',
            'icon' => 'keys',
            'group' => 'Settings',
        ],
        [
            'label' => 'Cloud Token',
            'route' => 'server.cloud-provider-token',
            'active' => $activeMenu === 'cloud-provider-token',
            'icon' => 'subscription',
            'group' => 'Settings',
            'visible' => (bool) ($server->hetzner_server_id || $server->vultr_instance_id),
        ],
        [
            'label' => 'CA Certificate',
            'route' => 'server.ca-certificate',
            'active' => $activeMenu === 'ca-certificate',
            'icon' => 'file',
            'group' => 'Settings',
        ],
        [
            'label' => 'Cloudflare Tunnel',
            'route' => 'server.cloudflare-tunnel',
            'active' => $activeMenu === 'cloudflare-tunnel',
            'icon' => 'globe',
            'group' => 'Networking',
            'visible' => ! $server->isLocalhost(),
        ],
        [
            'label' => 'Destinations',
            'route' => 'server.destinations',
            'active' => $activeMenu === 'destinations',
            'icon' => 'destinations',
            'group' => 'Networking',
            'visible' => $server->isFunctional(),
        ],
        [
            'label' => 'Swarm',
            'route' => 'server.swarm',
            'active' => $activeMenu === 'swarm',
            'icon' => 'layers',
            'group' => 'Networking',
            'visible' => ! $server->isBuildServer() && ! $server->settings->is_cloudflare_tunnel,
        ],
        [
            'label' => 'Docker Cleanup',
            'route' => 'server.docker-cleanup',
            'active' => $activeMenu === 'docker-cleanup',
            'icon' => 'storages',
            'group' => 'Operations',
            'visible' => $server->isFunctional(),
        ],
        [
            'label' => 'Log Drains',
            'route' => 'server.log-drains',
            'active' => $activeMenu === 'log-drains',
            'icon' => 'notifications',
            'group' => 'Operations',
            'visible' => $server->isFunctional(),
        ],
        [
            'label' => 'Metrics',
            'route' => 'server.metrics',
            'active' => $activeMenu === 'metrics',
            'icon' => 'dashboard',
            'group' => 'Operations',
            'visible' => $server->isFunctional(),
        ],
        [
            'label' => 'Danger',
            'route' => 'server.delete',
            'active' => $activeMenu === 'danger',
            'icon' => 'admin',
            'group' => 'Danger zone',
            'visible' => ! $server->isLocalhost(),
        ],
    ];

    $serverMenuItems = collect($serverMenuItems)
        ->filter(fn (array $item): bool => $item['visible'] ?? true)
        ->values();
    $groupedServerMenuItems = $serverMenuItems->groupBy('group');
@endphp

<aside class="application-settings-navigation min-w-0 xl:sticky xl:top-26 xl:self-start">
    <nav aria-label="Server configuration sections"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        @foreach ($groupedServerMenuItems as $groupLabel => $groupItems)
            @unless ($loop->first)
                <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]"
                    aria-hidden="true"></div>
            @endunless
            <div class="nav-section hidden xl:block">{{ $groupLabel }}</div>
            @foreach ($groupItems as $menuItem)
                <a wire:key="server-settings-link-{{ str($menuItem['label'])->slug() }}"
                    @class([
                        'menu-item',
                        'menu-item-active' => $menuItem['active'],
                    ])
                    {{ wireNavigate() }}
                    href="{{ route($menuItem['route'], $serverRouteParameters) }}">
                    <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                    <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                </a>
            @endforeach
        @endforeach
    </nav>
</aside>
