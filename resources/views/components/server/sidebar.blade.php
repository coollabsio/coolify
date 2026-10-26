@props(['server', 'activeMenu', 'activeSubMenu' => null])

@php
    $serverRouteParameters = ['server_uuid' => $server->uuid];
    $sentinelStatus = $server->sentinelStatus();
    $proxyNotRunning = $server->proxySet() && ($server->proxy->status ?? 'unknown') !== 'running';
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
            'disabled' => ! $server->isFunctional(),
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
            'visible' => (bool) ($server->hetzner_server_id || $server->vultr_instance_id || $server->digitalocean_droplet_id || $server->hostinger_virtual_machine_id),
        ],
        [
            'label' => 'CA Certificate',
            'route' => 'server.ca-certificate',
            'active' => $activeMenu === 'ca-certificate',
            'icon' => 'file',
            'group' => 'Settings',
            'disabled' => ! $server->isFunctional(),
        ],
        [
            'label' => 'Cloudflare Tunnel',
            'route' => 'server.cloudflare-tunnel',
            'active' => $activeMenu === 'cloudflare-tunnel',
            'icon' => 'cloudflare',
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
            'disabled' => ! $server->isFunctional(),
            'tracks_proxy_configuration' => true,
            'children' => [
                ['label' => 'Configuration', 'route' => 'server.proxy', 'active' => $activeSubMenu === 'configuration', 'icon' => 'settings'],
                ['label' => 'Dynamic Configurations', 'route' => 'server.proxy.dynamic-confs', 'active' => $activeSubMenu === 'dynamic-confs', 'icon' => 'sliders', 'visible' => $server->proxySet()],
                ['label' => 'TLS Certificates', 'route' => 'server.proxy.certificates', 'active' => $activeSubMenu === 'certificates', 'icon' => 'shield-star', 'visible' => $server->proxyType() === \App\Enums\ProxyTypes::TRAEFIK->value],
                ['label' => 'Logs', 'route' => 'server.proxy.logs', 'active' => $activeSubMenu === 'logs', 'icon' => 'file-content', 'visible' => $server->proxySet(), 'navigate' => false],
            ],
        ],
        [
            'label' => 'Sentinel',
            'route' => 'server.sentinel',
            'active' => $activeMenu === 'sentinel',
            'icon' => 'shield-star',
            'group' => 'Platform',
            'visible' => ! $server->isSwarm() && $server->canHostResources() && auth()->user()?->can('viewSentinel', $server),
            'disabled' => ! $server->isFunctional(),
            'warning' => $server->isSentinelEnabled() && $sentinelStatus === 'out_of_sync',
            'tracks_sentinel_status' => true,
            'children' => [
                ['label' => 'Configuration', 'route' => 'server.sentinel', 'active' => $activeSubMenu === 'configuration', 'icon' => 'settings'],
                ['label' => 'Logs', 'route' => 'server.sentinel.logs', 'active' => $activeSubMenu === 'logs', 'icon' => 'file-content'],
            ],
        ],
        [
            'label' => 'Resources',
            'route' => 'server.resources',
            'active' => $activeMenu === 'resources',
            'icon' => 'projects',
            'group' => 'Platform',
            'disabled' => ! $server->isFunctional(),
        ],
        [
            'label' => 'Terminal',
            'route' => 'server.command',
            'active' => $activeMenu === 'terminal',
            'icon' => 'browser-terminal',
            'group' => 'Operations',
            'navigate' => false,
            'visible' => auth()->user()?->can('canAccessTerminal'),
            'disabled' => ! $server->isFunctional(),
        ],
        [
            'label' => 'Destinations',
            'route' => 'server.destinations',
            'active' => $activeMenu === 'destinations',
            'icon' => 'destinations',
            'group' => 'Networking',
            'disabled' => ! $server->isFunctional(),
        ],
        [
            'label' => 'Swarm',
            'route' => 'server.swarm',
            'active' => $activeMenu === 'swarm',
            'icon' => 'layers',
            'group' => 'Networking',
            'visible' => $server->team->usesSwarm() && ! $server->isBuildServer() && ! $server->settings->is_cloudflare_tunnel,
        ],
        [
            'label' => 'Images',
            'route' => 'server.docker-images',
            'active' => $activeMenu === 'docker-images',
            'icon' => 'layers',
            'group' => 'Operations',
            'disabled' => ! $server->isFunctional(),
        ],
        [
            'label' => 'Docker Cleanup',
            'route' => 'server.docker-cleanup',
            'active' => $activeMenu === 'docker-cleanup',
            'icon' => 'broom',
            'group' => 'Operations',
            'disabled' => ! $server->isFunctional(),
        ],
        [
            'label' => 'GitHub Runners',
            'route' => 'server.github-runners',
            'active' => $activeMenu === 'github-runners',
            'icon' => 'play-circle',
            'group' => 'Operations',
            'visible' => ! $server->isLocalhost(),
            'beta' => true,
            'disabled' => ! $server->isFunctional(),
        ],
        [
            'label' => 'Registries',
            'route' => 'server.registries',
            'active' => $activeMenu === 'registries',
            'icon' => 'layers',
            'group' => 'Operations',
            'visible' => auth()->user()?->can('update', $server),
            'disabled' => ! $server->isFunctional(),
        ],
        [
            'label' => 'Log Drains',
            'route' => 'server.log-drains',
            'active' => $activeMenu === 'log-drains',
            'icon' => 'notifications',
            'group' => 'Operations',
            'disabled' => ! $server->isFunctional(),
        ],
        [
            'label' => 'Metrics',
            'route' => 'server.metrics',
            'active' => $activeMenu === 'metrics',
            'icon' => 'graph',
            'group' => 'Operations',
            'disabled' => ! $server->isFunctional(),
        ],
        [
            'label' => 'Analytics',
            'route' => 'server.analytics',
            'active' => $activeMenu === 'analytics',
            'icon' => 'analytics',
            'group' => 'Operations',
            'visible' => ! $server->isSwarm() && ! $server->isBuildServer(),
            'disabled' => ! $server->isFunctional(),
        ],
        [
            'label' => 'Security',
            'route' => 'server.security.patches',
            'active' => $activeMenu === 'security',
            'icon' => 'shield-alert',
            'group' => 'Security',
            'visible' => auth()->user()?->can('update', $server),
            'disabled' => ! $server->isFunctional(),
            'children' => [
                ['label' => 'Patching', 'route' => 'server.security.patches', 'active' => $activeSubMenu === 'patches', 'icon' => 'bandage', 'beta' => true],
                ['label' => 'Terminal Access', 'route' => 'server.security.terminal-access', 'active' => $activeSubMenu === 'terminal-access', 'icon' => 'browser-terminal', 'navigate' => false],
            ],
        ],
        [
            'label' => 'Transfer',
            'route' => 'server.transfer',
            'active' => $activeMenu === 'transfer',
            'icon' => 'arrow-right',
            'beta' => true,
            'group' => 'Operations',
            'visible' => ! $server->isLocalhost() && auth()->user()?->can('update', $server),
            'disabled' => ! $server->isFunctional(),
        ],
        [
            'label' => 'Danger Zone',
            'route' => 'server.delete',
            'active' => $activeMenu === 'danger',
            'icon' => 'danger',
            'group' => 'Danger Zone',
            // Coolify host (id 0) cannot be deleted. Other servers may still use
            // host.docker.internal (e.g. Lima VMs) and must keep the Danger menu.
            'visible' => ! $server->is_coolify_host,
        ],
    ];

    $serverMenuItems = collect($serverMenuItems)
        ->filter(fn (array $item): bool => $item['visible'] ?? true)
        ->values();
    $groupedServerMenuItems = $serverMenuItems->groupBy('group');
@endphp

@php
    // The main sidebar and the mobile drawer show this menu (see components/navbar.blade.php).
    registerMainNavContext(0, 'Servers', 'Back to the main menu');
@endphp

{{-- Rendered twice: in the page (CSS hides it; it keeps the command palette items), and teleported into the main sidebar.
     Both copies keep their own warning state (proxy, Sentinel). --}}
@foreach (['page', 'main-nav'] as $placement)
@if ($placement === 'main-nav')
@teleport('#main-nav-context-0')
<div class="nav-context-menu"
    x-data="{
        proxyConfigurationPending: @js($server->hasPendingProxyConfiguration()),
        traefikOutdated: @js($server->hasCurrentTraefikOutdatedInfo()),
        proxyNotRunning: @js($proxyNotRunning),
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
        proxyNotRunning = $event.detail.proxyNotRunning;
    "
    @sentinel-status-changed.window="
        sentinelOutOfSync = $event.detail.outOfSync;
        scheduleSentinelExpiry($event.detail.expiresInMilliseconds);
    ">
@else
<aside class="application-settings-navigation in-main-nav min-w-0"
    data-settings-search-items="{{ json_encode(settingsSearchItems($groupedServerMenuItems, $serverRouteParameters)) }}"
    x-data="{
        proxyConfigurationPending: @js($server->hasPendingProxyConfiguration()),
        traefikOutdated: @js($server->hasCurrentTraefikOutdatedInfo()),
        proxyNotRunning: @js($proxyNotRunning),
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
        proxyNotRunning = $event.detail.proxyNotRunning;
    "
    @sentinel-status-changed.window="
        sentinelOutOfSync = $event.detail.outOfSync;
        scheduleSentinelExpiry($event.detail.expiresInMilliseconds);
    ">
@endif
    <nav aria-label="Server configuration sections"
        class="grid grid-cols-1 gap-0.5">
        @foreach ($groupedServerMenuItems as $groupLabel => $groupItems)
            @unless ($loop->first)
                <div class="my-2 border-t border-neutral-200 dark:border-white/[0.06]"
                    aria-hidden="true"></div>
            @endunless
            @foreach ($groupItems as $menuItem)
                @if ($menuItem['disabled'] ?? false)
                    <span wire:key="{{ $placement }}-server-settings-link-{{ str($menuItem['label'])->slug() }}"
                        class="menu-item cursor-not-allowed opacity-40" aria-disabled="true"
                        title="Validate the server connection first.">
                        <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                        <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                    </span>
                    @continue
                @endif
                <a wire:key="{{ $placement }}-server-settings-link-{{ str($menuItem['label'])->slug() }}"
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
                            x-show="proxyConfigurationPending || traefikOutdated || proxyNotRunning"
                            class="ml-auto size-3.5 shrink-0 text-orange-500 dark:text-warning" />
                    @elseif ($menuItem['tracks_sentinel_status'] ?? false)
                        <x-reicon name="alert-triangle" x-cloak x-show="sentinelOutOfSync"
                            class="ml-auto size-3.5 shrink-0 text-orange-500 dark:text-warning" />
                    @elseif ($menuItem['warning'] ?? false)
                        <x-reicon name="alert-triangle"
                            class="ml-auto size-3.5 shrink-0 text-orange-500 dark:text-warning" />
                    @elseif ($menuItem['beta'] ?? false)
                        <x-beta-badge class="ml-auto shrink-0" />
                    @endif
                </a>
                @if ($menuItem['active'] && isset($menuItem['children']))
                    <div class="col-span-full grid grid-cols-1 gap-0.5 border-l border-neutral-200 pl-2 dark:border-white/[0.08]">
                        @foreach (collect($menuItem['children'])->filter(fn (array $child): bool => $child['visible'] ?? true) as $child)
                            <a wire:key="{{ $placement }}-server-settings-child-{{ str($menuItem['label'].'-'.$child['label'])->slug() }}"
                                @class(['menu-item', 'menu-item-active' => $child['active']])
                                @if ($child['navigate'] ?? true) {{ wireNavigate() }} @endif
                                href="{{ route($child['route'], $serverRouteParameters) }}">
                                <x-reicon :name="$child['icon']" class="menu-item-icon" />
                                <span class="menu-item-label">{{ $child['label'] }}</span>
                                @if ($child['beta'] ?? false)
                                    <x-beta-badge class="ml-auto shrink-0" />
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            @endforeach
        @endforeach
    </nav>
@if ($placement === 'main-nav')
</div>
@endteleport
@else
</aside>
@endif
@endforeach
