@php
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
    $securityMenuItems = [
        [
            'label' => 'Server Patching',
            'route' => 'server.security.patches',
            'active' => request()->routeIs('server.security.patches'),
        ],
        [
            'label' => 'Terminal Access',
            'route' => 'server.security.terminal-access',
            'active' => request()->routeIs('server.security.terminal-access'),
            'navigate' => false,
        ],
    ];
    $serverPageItems = array_values(array_filter(
        $serverPageItems,
        fn (array $item): bool => $item['visible'] ?? true,
    ));
    $activeSecurityMenuItem = collect($securityMenuItems)->firstWhere('active', true) ?? $securityMenuItems[0];
    $activeSecurityMenuValue = (($activeSecurityMenuItem['navigate'] ?? true) ? 'navigate' : 'location').'|security|'.route($activeSecurityMenuItem['route'], $parameters);
@endphp

<div class="w-full md:w-auto">
    <div class="mb-4 w-full border-b-2 border-solid border-neutral-200 pb-4 md:hidden dark:border-coolgray-200">
        <label id="server-mobile-section-label" for="server-mobile-section" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">Section</label>
        <select id="server-mobile-section" class="select w-full" aria-label="Security menu"
            data-current-value="{{ $activeSecurityMenuValue }}"
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
                syncFromLocation() {
                    const currentUrl = new URL(window.location.href);
                    const matchingOptions = Array.from(this.$el.options).filter((option) => {
                        const optionUrl = new URL(option.value.split('|').slice(2).join('|'), window.location.origin);

                        return optionUrl.pathname === currentUrl.pathname;
                    });
                    const selectedOption = matchingOptions.find((option) => option.value.startsWith('navigate|security|') || option.value.startsWith('location|security|')) || matchingOptions[0];

                    if (selectedOption) {
                        this.selected = selectedOption.value;
                    }
                },
            }"
            x-on:livewire:navigated.window="syncFromLocation()"
            x-model="selected"
            x-on:change="
                const value = $event.target.value;
                const url = value.split('|').slice(2).join('|');

                if (value.startsWith('navigate|')) {
                    window.Livewire?.navigate ? window.Livewire.navigate(url) : window.location.href = url;
                    return;
                }

                window.location.href = url;
            ">
            <optgroup label="Server">
                @foreach ($serverPageItems as $menuItem)
                    <option value="{{ ($menuItem['navigate'] ?? true) ? 'navigate' : 'location' }}|server|{{ route($menuItem['route'], $parameters) }}">
                        {{ $menuItem['label'] }}
                    </option>
                @endforeach
            </optgroup>
            <optgroup label="Security">
                @foreach ($securityMenuItems as $menuItem)
                    <option value="{{ ($menuItem['navigate'] ?? true) ? 'navigate' : 'location' }}|security|{{ route($menuItem['route'], $parameters) }}">
                        {{ $menuItem['label'] }}
                    </option>
                @endforeach
            </optgroup>
        </select>
    </div>

    <div class="sub-menu-wrapper hidden md:flex">
        @foreach ($securityMenuItems as $menuItem)
            <a class="{{ $menuItem['active'] ? 'sub-menu-item menu-item-active' : 'sub-menu-item' }}" @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                href="{{ route($menuItem['route'], $parameters) }}">
                <span class="menu-item-label">{{ $menuItem['label'] }}</span>
            </a>
        @endforeach
    </div>
</div>
