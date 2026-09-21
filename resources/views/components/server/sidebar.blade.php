@props(['server', 'activeMenu', 'activeSubMenu' => null])

@php
    $serverRouteParameters = ['server_uuid' => $server->uuid];
    $sentinelStatus = $server->sentinelStatus();
    $sentinelStatusStartedAt = $server->sentinel_waiting_since ?? \Illuminate\Support\Carbon::parse($server->sentinel_updated_at);
    $sentinelTimeoutSeconds = $server->sentinel_waiting_since !== null
        ? $server->firstSentinelReportTimeoutSeconds()
        : $server->waitBeforeDoingSshCheck();
    $sentinelExpiresInMilliseconds = max(
        0,
        ($sentinelStatusStartedAt->copy()->addSeconds($sentinelTimeoutSeconds)->timestamp - now()->timestamp) * 1000,
    );
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
            'label' => 'Proxy',
            'route' => 'server.proxy',
            'active' => $activeMenu === 'proxy',
            'icon' => 'network',
            'group' => 'Platform',
            'visible' => ! $server->isSwarmWorker() && $server->canHostResources(),
            'warning' => $server->hasCurrentTraefikOutdatedInfo(),
            'tracks_proxy_configuration' => true,
            'children' => [
                ['label' => 'Configuration', 'route' => 'server.proxy', 'active' => $activeSubMenu === 'configuration', 'icon' => 'settings'],
                ['label' => 'Dynamic Configurations', 'route' => 'server.proxy.dynamic-confs', 'active' => $activeSubMenu === 'dynamic-confs', 'icon' => 'sliders', 'visible' => $server->proxySet()],
                ['label' => 'Logs', 'route' => 'server.proxy.logs', 'active' => $activeSubMenu === 'logs', 'icon' => 'file-content', 'visible' => $server->proxySet(), 'navigate' => false],
            ],
        ],
        [
            'label' => 'Sentinel',
            'route' => 'server.sentinel',
            'active' => request()->routeIs('server.sentinel', 'server.sentinel.*'),
            'icon' => 'shield-star',
            'group' => 'Platform',
            'visible' => $server->isFunctional() && ! $server->isSwarm() && $server->canHostResources() && auth()->user()?->can('viewSentinel', $server),
            'warning' => $server->isSentinelEnabled() && $sentinelStatus === 'out_of_sync',
            'tracks_sentinel_status' => true,
            'children' => [
                ['label' => 'Configuration', 'route' => 'server.sentinel', 'active' => request()->routeIs('server.sentinel'), 'icon' => 'settings'],
                ['label' => 'Logs', 'route' => 'server.sentinel.logs', 'active' => request()->routeIs('server.sentinel.logs'), 'icon' => 'file-content'],
            ],
        ],
        [
            'label' => 'Resources',
            'route' => 'server.resources',
            'active' => $activeMenu === 'resources',
            'icon' => 'projects',
            'group' => 'Platform',
        ],
        [
            'label' => 'Terminal',
            'route' => 'server.command',
            'active' => $activeMenu === 'terminal',
            'icon' => 'browser-terminal',
            'group' => 'Operations',
            'navigate' => false,
            'visible' => auth()->user()?->can('canAccessTerminal'),
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
            'icon' => 'broom',
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
            'icon' => 'graph',
            'group' => 'Operations',
            'visible' => $server->isFunctional(),
        ],
        [
            'label' => 'Analytics',
            'route' => 'server.analytics',
            'active' => $activeMenu === 'analytics',
            'icon' => 'analytics',
            'group' => 'Operations',
            'visible' => $server->isFunctional() && ! $server->isSwarm() && ! $server->isBuildServer(),
        ],
        [
            'label' => 'Security',
            'route' => 'server.security.patches',
            'active' => request()->routeIs('server.security.*'),
            'icon' => 'shield-alert',
            'group' => 'Security',
            'visible' => auth()->user()?->can('update', $server),
            'children' => [
                ['label' => 'Server Patching', 'route' => 'server.security.patches', 'active' => request()->routeIs('server.security.patches'), 'icon' => 'bandage'],
                ['label' => 'Terminal Access', 'route' => 'server.security.terminal-access', 'active' => request()->routeIs('server.security.terminal-access'), 'icon' => 'browser-terminal', 'navigate' => false],
            ],
        ],
        [
            'label' => 'Transfer',
            'route' => 'server.transfer',
            'active' => $activeMenu === 'transfer',
            'icon' => 'arrow-right',
            'group' => 'Operations',
            'visible' => isDev() && ! $server->isLocalhost() && auth()->user()?->can('view', $server),
        ],
        [
            'label' => 'Danger',
            'route' => 'server.delete',
            'active' => $activeMenu === 'danger',
            'icon' => 'shield-alert',
            'group' => 'Danger zone',
            // Coolify host (id 0) cannot be deleted. Other servers may still use
            // host.docker.internal (e.g. Lima VMs) and must keep the Danger menu.
            'visible' => ! $server->is_coolify_host,
        ],
    ];

    $serverMenuItems = collect($serverMenuItems)
        ->filter(fn (array $item): bool => $item['visible'] ?? true)
        ->values();
    $groupedServerMenuItems = $serverMenuItems->groupBy('group');

    // Group that holds the current page (item or nested child) — the only one
    // expanded by default.
    $activeGroup = (string) $groupedServerMenuItems->search(fn ($items) => $items->contains(
        fn ($item) => ($item['active'] ?? false)
            || collect($item['children'] ?? [])->contains(fn ($child) => $child['active'] ?? false)
    ));
@endphp

<aside class="application-settings-navigation min-w-0 xl:self-start"
    x-data="{
        proxyConfigurationPending: @js($server->hasPendingProxyConfiguration()),
        traefikOutdated: @js($server->hasCurrentTraefikOutdatedInfo()),
        sentinelOutOfSync: @js($server->isSentinelEnabled() && $sentinelStatus === 'out_of_sync'),
        sentinelExpiryTimer: null,
        scheduleSentinelExpiry(delay) {
            clearTimeout(this.sentinelExpiryTimer);
            if (!this.sentinelOutOfSync) {
                this.sentinelExpiryTimer = setTimeout(() => this.sentinelOutOfSync = true, delay);
            }
        }
    }"
    x-init="scheduleSentinelExpiry(@js($sentinelExpiresInMilliseconds))"
    @proxy-configuration-state-changed.window="
        proxyConfigurationPending = $event.detail.pending;
        traefikOutdated = $event.detail.traefikOutdated;
    "
    @sentinel-status-changed.window="
        sentinelOutOfSync = $event.detail.outOfSync;
        scheduleSentinelExpiry($event.detail.expiresInMilliseconds);
    ">
    <nav aria-label="Server configuration sections"
        x-data="settingsSidebarAccordion({ activeGroup: @js($activeGroup), storageKey: 'coolify.settings-sidebar.server' })"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        @foreach ($groupedServerMenuItems as $groupLabel => $groupItems)
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
                <a wire:key="server-settings-link-{{ str($menuItem['label'])->slug() }}"
                    @class([
                        'menu-item',
                        'menu-item-active' => $menuItem['active'],
                    ])
                    @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                    href="{{ route($menuItem['route'], $serverRouteParameters) }}">
                    <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                    <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                    @if ($menuItem['tracks_proxy_configuration'] ?? false)
                        <x-reicon name="alert-triangle" x-cloak
                            x-show="proxyConfigurationPending || traefikOutdated"
                            class="ml-auto size-3.5 shrink-0 text-orange-500 dark:text-warning" />
                    @elseif ($menuItem['tracks_sentinel_status'] ?? false)
                        <x-reicon name="alert-triangle" x-cloak x-show="sentinelOutOfSync"
                            class="ml-auto size-3.5 shrink-0 text-orange-500 dark:text-warning" />
                    @elseif ($menuItem['warning'] ?? false)
                        <x-reicon name="alert-triangle"
                            class="ml-auto size-3.5 shrink-0 text-orange-500 dark:text-warning" />
                    @endif
                </a>
                @if ($menuItem['active'] && isset($menuItem['children']))
                    <div class="col-span-full grid grid-cols-2 gap-0.5 border-l border-neutral-200 pl-2 sm:grid-cols-3 xl:grid-cols-1 dark:border-white/[0.08]">
                        @foreach (collect($menuItem['children'])->filter(fn (array $child): bool => $child['visible'] ?? true) as $child)
                            <a wire:key="server-settings-child-{{ str($menuItem['label'].'-'.$child['label'])->slug() }}"
                                @class(['menu-item', 'menu-item-active' => $child['active']])
                                @if ($child['navigate'] ?? true) {{ wireNavigate() }} @endif
                                href="{{ route($child['route'], $serverRouteParameters) }}">
                                <x-reicon :name="$child['icon']" class="menu-item-icon" />
                                <span class="menu-item-label">{{ $child['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            @endforeach
            </div>
        @endforeach
    </nav>
</aside>
