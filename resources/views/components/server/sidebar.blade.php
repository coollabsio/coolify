@php
    $serverRouteParameters = ['server_uuid' => $server->uuid];
    $serverPageItems = [
        [
            'label' => 'Configuration',
            'route' => 'server.show',
            'active' => request()->routeIs('server.show', 'server.advanced', 'server.private-key', 'server.cloud-provider-token', 'server.ca-certificate', 'server.cloudflare-tunnel', 'server.docker-cleanup', 'server.destinations', 'server.log-drains', 'server.metrics', 'server.swarm', 'server.delete'),
        ],
        [
            'label' => 'Proxy',
            'route' => 'server.proxy',
            'active' => request()->routeIs('server.proxy', 'server.proxy.*'),
            'visible' => ! $server->isSwarmWorker() && ! $server->settings->is_build_server,
        ],
        [
            'label' => 'Sentinel',
            'route' => 'server.sentinel',
            'active' => request()->routeIs('server.sentinel', 'server.sentinel.*'),
            'visible' => $server->isFunctional() && ! $server->isSwarm() && ! $server->settings->is_build_server && auth()->user()?->can('viewSentinel', $server),
        ],
        [
            'label' => 'Resources',
            'route' => 'server.resources',
            'active' => request()->routeIs('server.resources'),
        ],
        [
            'label' => 'Terminal',
            'route' => 'server.command',
            'active' => request()->routeIs('server.command'),
            'navigate' => false,
            'visible' => auth()->user()?->can('canAccessTerminal'),
        ],
        [
            'label' => 'Security',
            'route' => 'server.security.patches',
            'active' => request()->routeIs('server.security.patches'),
            'visible' => auth()->user()?->can('update', $server),
        ],
    ];
    $serverMenuItems = [
        [
            'label' => 'General',
            'route' => 'server.show',
            'active' => $activeMenu === 'general',
        ],
        [
            'label' => 'Advanced',
            'route' => 'server.advanced',
            'active' => $activeMenu === 'advanced',
            'visible' => $server->isFunctional(),
        ],
        [
            'label' => 'Private Key',
            'route' => 'server.private-key',
            'active' => $activeMenu === 'private-key',
        ],
        [
            'label' => 'Cloud Token',
            'route' => 'server.cloud-provider-token',
            'active' => $activeMenu === 'cloud-provider-token',
            'visible' => (bool) ($server->hetzner_server_id || $server->vultr_instance_id),
        ],
        [
            'label' => 'CA Certificate',
            'route' => 'server.ca-certificate',
            'active' => $activeMenu === 'ca-certificate',
        ],
        [
            'label' => 'Cloudflare Tunnel',
            'route' => 'server.cloudflare-tunnel',
            'active' => $activeMenu === 'cloudflare-tunnel',
            'visible' => ! $server->isLocalhost(),
        ],
        [
            'label' => 'Docker Cleanup',
            'route' => 'server.docker-cleanup',
            'active' => $activeMenu === 'docker-cleanup',
            'visible' => $server->isFunctional(),
        ],
        [
            'label' => 'Destinations',
            'route' => 'server.destinations',
            'active' => $activeMenu === 'destinations',
            'visible' => $server->isFunctional(),
        ],
        [
            'label' => 'Log Drains',
            'route' => 'server.log-drains',
            'active' => $activeMenu === 'log-drains',
            'visible' => $server->isFunctional(),
        ],
        [
            'label' => 'Metrics',
            'route' => 'server.metrics',
            'active' => $activeMenu === 'metrics',
            'visible' => $server->isFunctional(),
        ],
        [
            'label' => 'Swarm',
            'route' => 'server.swarm',
            'active' => $activeMenu === 'swarm',
            'visible' => ! $server->isBuildServer() && ! $server->settings->is_cloudflare_tunnel,
        ],
        [
            'label' => 'Danger',
            'route' => 'server.delete',
            'active' => $activeMenu === 'danger',
            'visible' => ! $server->isLocalhost(),
        ],
    ];

    $serverPageItems = array_values(array_filter(
        $serverPageItems,
        fn (array $item): bool => $item['visible'] ?? true,
    ));
    $serverMenuItems = array_values(array_filter(
        $serverMenuItems,
        fn (array $item): bool => $item['visible'] ?? true,
    ));
    $activeServerMenuItem = collect($serverMenuItems)->firstWhere('active', true);
    $activeServerPageItem = collect($serverPageItems)->firstWhere('active', true);
    $activeServerMobileItem = $activeServerMenuItem ?? $activeServerPageItem ?? $serverMenuItems[0];
    $activeServerMobileGroup = $activeServerMenuItem ? 'configuration' : 'server';
    $activeServerMobileNavigation = ($activeServerMobileItem['navigate'] ?? true) ? 'navigate' : 'location';
    $activeServerMenuValue = $activeServerMobileNavigation.'|'.$activeServerMobileGroup.'|'.route($activeServerMobileItem['route'], $serverRouteParameters);
@endphp

<div class="w-full md:w-auto">
    <div class="mb-4 w-full border-b-2 border-solid border-neutral-200 pb-4 md:hidden dark:border-coolgray-200">
        <label id="server-mobile-section-label" for="server-mobile-section" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Section</label>
        <select id="server-mobile-section" class="select w-full" aria-label="Server menu"
            data-current-value="{{ $activeServerMenuValue }}"
            x-data="{
                init() {
                    this.syncFromLocation();
                    window.Livewire?.hook?.('morphed', ({ el }) => {
                        if (el.contains(this.$el)) {
                            queueMicrotask(() => this.syncFromLocation());
                        }
                    });
                },
                selected: $el.dataset.currentValue,
                current: $el.dataset.currentValue,
                syncFromLocation() {
                    const currentUrl = new URL(window.location.href);
                    const selectedOption = Array.from(this.$el.options).find((option) => {
                        if (!option.value.startsWith('navigate|') && !option.value.startsWith('location|')) {
                            return false;
                        }

                        const optionUrl = new URL(option.value.split('|').slice(2).join('|'), window.location.origin);

                        return optionUrl.pathname === currentUrl.pathname;
                    });

                    if (selectedOption) {
                        this.current = selectedOption.value;
                        this.selected = selectedOption.value;
                    }
                },
            }"
            x-on:livewire:navigated.window="syncFromLocation()"
            x-model="selected"
            x-on:change="
                const value = $event.target.value;

                if (value.startsWith('navigate|')) {
                    const url = value.split('|').slice(2).join('|');
                    window.Livewire?.navigate ? window.Livewire.navigate(url) : window.location.href = url;
                    return;
                }

                if (value.startsWith('location|')) {
                    const url = value.split('|').slice(2).join('|');
                    window.location.href = url;
                }
            ">
            <optgroup label="Server">
                @foreach ($serverPageItems as $menuItem)
                    <option value="{{ ($menuItem['navigate'] ?? true) ? 'navigate' : 'location' }}|server|{{ route($menuItem['route'], $serverRouteParameters) }}">
                        {{ $menuItem['label'] }}
                    </option>
                @endforeach
            </optgroup>
            <optgroup label="Configuration">
                @foreach ($serverMenuItems as $menuItem)
                    <option value="navigate|configuration|{{ route($menuItem['route'], $serverRouteParameters) }}">
                        {{ $menuItem['label'] }}
                    </option>
                @endforeach
            </optgroup>
        </select>
    </div>

    <div class="sub-menu-wrapper hidden md:flex">
        @foreach ($serverMenuItems as $menuItem)
            <a class="sub-menu-item {{ $menuItem['active'] ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
                href="{{ route($menuItem['route'], $serverRouteParameters) }}"><span class="menu-item-label">{{ $menuItem['label'] }}</span></a>
        @endforeach
    </div>
</div>
