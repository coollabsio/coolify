<nav class="w-full max-w-[1180px] pb-3 lg:pb-0">
    <x-slide-over @startproxy.window="slideOverOpen = true" fullScreen closeWithX>
        <x-slot:title>Proxy Startup Logs</x-slot:title>
        <x-slot:content>
            @if ($server->id === 0)
                <div class="mb-4 rounded-lg border border-warning/30 bg-warning/10 p-3 text-sm text-warning">
                    <span class="font-semibold">Note:</span> This is the localhost server where Coolify runs.
                    During proxy restart, the connection may be temporarily lost.
                    If logs stop updating, please refresh the browser after a few minutes.
                </div>
            @endif
            <livewire:activity-monitor header="Logs" fullHeight />
        </x-slot:content>
    </x-slide-over>

    @php
        $serverRouteParameters = ['server_uuid' => $server->uuid];
        $serverMenuItems = [
            [
                'label' => 'Configuration',
                'route' => 'server.show',
                'active' => request()->routeIs(
                    'server.show',
                    'server.advanced',
                    'server.private-key',
                    'server.cloud-provider-token',
                    'server.ca-certificate',
                    'server.cloudflare-tunnel',
                    'server.docker-cleanup',
                    'server.destinations',
                    'server.log-drains',
                    'server.metrics',
                    'server.swarm',
                    'server.delete',
                ),
            ],
            [
                'label' => 'Proxy',
                'route' => 'server.proxy',
                'active' => request()->routeIs('server.proxy', 'server.proxy.*'),
                'visible' => ! $server->isSwarmWorker() && ! $server->settings->is_build_server,
                'warning' => $this->hasTraefikOutdated,
            ],
            [
                'label' => 'Sentinel',
                'route' => 'server.sentinel',
                'active' => request()->routeIs('server.sentinel', 'server.sentinel.*'),
                'visible' => $server->isFunctional()
                    && ! $server->isSwarm()
                    && ! $server->settings->is_build_server
                    && auth()->user()?->can('viewSentinel', $server),
                'warning' => $server->isSentinelEnabled() && ! $server->isSentinelLive(),
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
                'active' => request()->routeIs('server.security.*'),
                'visible' => auth()->user()?->can('update', $server),
            ],
        ];

        $serverMenuItems = array_values(array_filter(
            $serverMenuItems,
            fn (array $item): bool => $item['visible'] ?? true,
        ));
        $activeServerMenuItem = collect($serverMenuItems)->firstWhere('active', true) ?? $serverMenuItems[0];
        $activeServerMenuNavigation = ($activeServerMenuItem['navigate'] ?? true) ? 'navigate' : 'location';
        $activeServerMenuValue = $activeServerMenuNavigation.'|'.route(
            $activeServerMenuItem['route'],
            $serverRouteParameters,
        );
        $showSentinelStatus = $server->isFunctional() && $server->isSentinelEnabled();
    @endphp

    @teleport('#server-topbar-context')
        <div data-testid="server-topbar-context"
            class="flex min-w-0 items-center gap-1 text-[13px]">
            <span class="shrink-0 px-0.5 text-neutral-300 dark:text-fg-faint">/</span>
            <span class="relative flex min-w-0 shrink items-center gap-2 px-1"
                x-data="{ open: false, search: '', servers: @js($serverSwitcherOptions) }"
                @click.outside="open = false" @keydown.escape.window="open = false">
                <button type="button"
                    class="flex h-8 max-w-56 min-w-0 items-center gap-1.5 rounded-md px-2 transition-colors hover:bg-neutral-100 dark:hover:bg-white/[0.05] xl:max-w-72"
                    @click="open = !open" :aria-expanded="open" aria-label="Switch server">
                    <span class="min-w-0 truncate font-semibold text-black dark:text-fg">
                        {{ $server->name }}
                    </span>
                    <x-reicon name="chevron-down" class="size-3 shrink-0 text-neutral-400 dark:text-fg-faint" />
                </button>
                <div x-cloak x-show="open" x-transition.origin.top.left
                    class="listbox-panel top-9! left-1! z-[90]! w-64! min-w-0!">
                    <div class="border-b border-neutral-200 p-1.5 dark:border-white/[0.08]">
                        <div class="relative">
                            <x-reicon name="search"
                                class="pointer-events-none absolute top-1/2 left-2 size-3 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                            <input type="search" x-model.debounce.100ms="search" placeholder="Filter servers…"
                                class="h-7! w-full rounded-md! py-0! pr-2! pl-7! text-[11px]!">
                        </div>
                    </div>
                    <template x-for="option in servers.filter((item) => item.name.toLowerCase().includes(search.toLowerCase()))"
                        :key="option.uuid">
                        <a :href="option.href" {{ wireNavigate() }} @click="open = false"
                            class="listbox-option gap-2.5!">
                            <span class="size-1.5 shrink-0 rounded-full"
                                :class="option.functional ? 'bg-success' : 'bg-error'"></span>
                            <span class="min-w-0 flex-1 truncate" x-text="option.name"></span>
                            <x-reicon name="check"
                                class="size-3.5 shrink-0 text-coollabs dark:text-warning"
                                x-show="option.uuid === '{{ $server->uuid }}'" />
                        </a>
                    </template>
                </div>
                <x-status-badge :status="$server->isFunctional() ? 'Ready' : 'Validation required'"
                    :type="$server->isFunctional() ? 'success' : 'warning'" />
            </span>

            <div class="hidden shrink-0 items-center gap-1 xl:flex">
                @if ($server->proxySet())
                    @if ($proxyStatus === 'running')
                        <x-status-badge label="Proxy" status="Running" type="success" />
                    @elseif (in_array($proxyStatus, ['restarting', 'stopping', 'starting']))
                        <x-status-badge label="Proxy" :status="str($proxyStatus)->headline()" type="warning" />
                    @elseif (data_get($server, 'proxy.force_stop'))
                        <x-status-badge wire:loading.remove wire:target="checkProxyStatus" label="Proxy"
                            status="Force stopped" type="error" />
                    @elseif ($proxyStatus === 'exited')
                        <x-status-badge wire:loading.remove wire:target="checkProxyStatus" label="Proxy"
                            status="Exited" type="error" />
                    @endif
                    <x-status-badge wire:loading wire:target="checkProxyStatus" label="Proxy"
                        status="Checking…" type="warning" />
                @endif
                @if ($showSentinelStatus)
                    <x-status-badge label="Sentinel"
                        :status="$server->isSentinelLive() ? 'In sync' : 'Out of sync'"
                        :type="$server->isSentinelLive() ? 'success' : 'error'" />
                @endif
                @if ($server->proxySet())
                    <x-status-badge as="button" wire:target="checkProxyStatus"
                        wire:loading.attr="disabled" wire:click="checkProxyStatus" status="Refresh"
                        type="neutral" title="Refresh status" aria-label="Refresh proxy status"
                        class="min-w-[4.5rem] cursor-pointer justify-center border-transparent hover:bg-neutral-200 disabled:cursor-wait disabled:opacity-70 dark:hover:bg-coolgray-300" />
                @endif
            </div>
        </div>
    @endteleport

    <div>
        {{-- Name lives in the desktop topbar (teleport) from lg up. Below that the
             mobile topbar has no resource context, so show the H1 through lg. --}}
        <div class="mb-3 w-full lg:hidden">
            <div class="flex min-w-0 flex-col gap-2">
                <h1 data-testid="server-subtitle"
                    class="min-w-0 truncate text-[24px]! leading-7! font-semibold! tracking-tight! text-black dark:text-fg">
                    {{ $server->name }}
                </h1>
                @if ($server->proxySet() || $showSentinelStatus)
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        @if ($server->proxySet())
                            @if ($proxyStatus === 'running')
                                <x-status-badge label="Proxy" status="Running" type="success" />
                            @elseif (in_array($proxyStatus, ['restarting', 'stopping', 'starting']))
                                <x-status-badge label="Proxy" :status="str($proxyStatus)->headline()" type="warning" />
                            @elseif (data_get($server, 'proxy.force_stop'))
                                <x-status-badge wire:loading.remove wire:target="checkProxyStatus" label="Proxy"
                                    status="Force stopped" type="error" />
                            @elseif ($proxyStatus === 'exited')
                                <x-status-badge wire:loading.remove wire:target="checkProxyStatus" label="Proxy"
                                    status="Exited" type="error" />
                            @endif
                        @endif
                        @if ($showSentinelStatus)
                            <x-status-badge label="Sentinel"
                                :status="$server->isSentinelLive() ? 'In sync' : 'Out of sync'"
                                :type="$server->isSentinelLive() ? 'success' : 'error'" />
                        @endif
                    </div>
                @endif
            </div>
        </div>

        {{-- Phone-only actions + primary tabs. From md the desktop tab row is used. --}}
        <div class="w-full md:hidden">
            @if ($server->proxySet())
                @can('manageProxy', $server)
                    <div id="server-mobile-actions" class="relative mb-3"
                        x-data="{ open: false }" @click.outside="open = false"
                        @keydown.escape.window="open = false">
                        <button type="button" class="button w-full justify-between" @click="open = !open"
                            :aria-expanded="open" aria-haspopup="menu">
                            <span class="inline-flex items-center gap-2">
                                <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                                Actions
                            </span>
                            <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
                                <x-reicon name="chevron-down" class="size-3 opacity-55" />
                            </span>
                        </button>

                        <div x-cloak x-show="open" x-transition.origin.top.left
                            class="listbox-panel top-full! left-0! right-0! mt-1! w-full! min-w-0!" role="menu">
                            @if ($proxyStatus === 'running')
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="open = false; document.getElementById('server-mobile-restart-proxy-trigger')?.click()"
                                    role="menuitem">
                                    <x-reicon name="restart" class="size-3.5 text-orange-500 dark:text-warning" />
                                    Restart Proxy
                                </button>
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="open = false; document.getElementById('server-mobile-stop-proxy-trigger')?.click()"
                                    role="menuitem">
                                    <x-reicon name="stop" class="size-3.5 text-error" />
                                    Stop Proxy
                                </button>
                                @if ($traefikDashboardAvailable)
                                    <a class="listbox-option justify-start! gap-2.5!" target="_blank"
                                        href="http://{{ $serverIp }}:8080" @click="open = false" role="menuitem">
                                        <x-reicon name="external-link" class="size-3.5 opacity-70" />
                                        Traefik Dashboard
                                    </a>
                                @endif
                            @else
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="open = false; $wire.dispatch('checkProxyEvent')" role="menuitem">
                                    <x-reicon name="play-circle" class="size-3.5 text-warning" />
                                    Start Proxy
                                </button>
                            @endif
                            <button type="button" class="listbox-option justify-start! gap-2.5!"
                                wire:click="checkProxyStatus" wire:loading.attr="disabled"
                                @click="open = false" role="menuitem">
                                <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                Refresh Proxy Status
                            </button>
                        </div>
                    </div>

                    <x-modal-confirmation title="Confirm Proxy Restart?" buttonTitle="Restart Proxy"
                        submitAction="restart" :actions="[
                            'This proxy will be stopped and started again.',
                            'All resources hosted on Coolify will be unavailable during the restart.',
                        ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Restart Proxy"
                        :dispatchEvent="true" dispatchEventType="restartEvent">
                        <x-slot:trigger>
                            <button id="server-mobile-restart-proxy-trigger" type="button" class="hidden">
                                Restart Proxy
                            </button>
                        </x-slot:trigger>
                    </x-modal-confirmation>
                    <x-modal-confirmation title="Confirm Proxy Stopping?" buttonTitle="Stop Proxy"
                        submitAction="stop(true)" :actions="[
                            'The Coolify proxy will be stopped.',
                            'All resources hosted on Coolify will be unavailable.',
                        ]" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Stop Proxy"
                        :dispatchEvent="true" dispatchEventType="stopEvent">
                        <x-slot:trigger>
                            <button id="server-mobile-stop-proxy-trigger" type="button" class="hidden">
                                Stop Proxy
                            </button>
                        </x-slot:trigger>
                    </x-modal-confirmation>
                @endcan
            @endif

            <div
                class="flex min-w-0 items-center gap-0.5 overflow-x-auto rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                @foreach ($serverMenuItems as $menuItem)
                    <a @class([
                        'app-tab shrink-0',
                        'bg-coollabs/10 text-coollabs ring-1 ring-coollabs/25 dark:bg-warning/15 dark:text-warning dark:ring-warning/25' => $menuItem['active'],
                    ])
                        @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                        href="{{ route($menuItem['route'], $serverRouteParameters) }}">
                        {{ $menuItem['label'] }}
                    </a>
                @endforeach
            </div>
        </div>

        <div class="hidden w-full items-center justify-between gap-4 md:flex lg:fixed lg:top-12 lg:right-0 lg:z-30 lg:h-12 lg:w-auto lg:border-b lg:border-neutral-200 lg:bg-white/95 lg:pr-4 lg:pl-2 lg:backdrop-blur lg:transition-[left] lg:duration-200 lg:dark:border-white/[0.06] lg:dark:bg-panel/95"
            :class="[typeof collapsed !== 'undefined' && collapsed ? 'lg:left-16' : 'lg:left-56']">
            <div
                class="application-primary-tabs flex min-w-0 items-center gap-0.5 overflow-x-auto rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                @foreach ($serverMenuItems as $menuItem)
                    <a wire:key="server-primary-nav-{{ str($menuItem['label'])->slug() }}"
                        @class([
                            'app-tab shrink-0 gap-1',
                            'bg-coollabs/10 text-coollabs shadow-sm ring-1 ring-coollabs/25 hover:bg-coollabs/15 dark:bg-warning/15 dark:text-warning dark:ring-warning/25 dark:hover:bg-warning/20' => $menuItem['active'],
                        ])
                        @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                        href="{{ route($menuItem['route'], $serverRouteParameters) }}">
                        {{ $menuItem['label'] }}
                        @if ($menuItem['warning'] ?? false)
                            <x-reicon name="alert-triangle"
                                class="size-3.5 text-orange-500 dark:text-warning" />
                        @endif
                    </a>
                @endforeach
            </div>

            @if ($server->proxySet())
                @can('manageProxy', $server)
                    <div
                        class="application-heading-actions flex shrink-0 items-center gap-0.5 rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                        @if ($proxyStatus === 'running')
                            <div class="mt-1" wire:loading wire:target="loadProxyConfiguration">
                                <x-loading text="Checking Traefik dashboard" />
                            </div>
                            @if ($traefikDashboardAvailable)
                                <a class="button" target="_blank" href="http://{{ $serverIp }}:8080">
                                    Traefik Dashboard
                                    <x-external-link />
                                </a>
                            @endif
                            <x-modal-confirmation title="Confirm Proxy Restart?" buttonTitle="Restart Proxy"
                                submitAction="restart" :actions="[
                                    'This proxy will be stopped and started again.',
                                    'All resources hosted on Coolify will be unavailable during the restart.',
                                ]" :confirmWithText="false" :confirmWithPassword="false"
                                step2ButtonText="Restart Proxy" :dispatchEvent="true"
                                dispatchEventType="restartEvent">
                                <x-slot:content>
                                    <x-forms.button title="Restart proxy">
                                        <x-reicon name="restart"
                                            class="size-4 text-orange-500 dark:text-warning" />
                                        Restart Proxy
                                    </x-forms.button>
                                </x-slot:content>
                            </x-modal-confirmation>
                            <x-modal-confirmation title="Confirm Proxy Stopping?" buttonTitle="Stop Proxy"
                                submitAction="stop(true)" :actions="[
                                    'The Coolify proxy will be stopped.',
                                    'All resources hosted on Coolify will be unavailable.',
                                ]" :confirmWithText="false" :confirmWithPassword="false"
                                step2ButtonText="Stop Proxy" :dispatchEvent="true"
                                dispatchEventType="stopEvent">
                                <x-slot:content>
                                    <x-forms.button isError title="Stop proxy">
                                        <x-reicon name="stop" class="size-4 text-error" />
                                        Stop Proxy
                                    </x-forms.button>
                                </x-slot:content>
                            </x-modal-confirmation>
                        @else
                            <x-forms.button @click="$wire.dispatch('checkProxyEvent')">
                                <x-reicon name="play-circle"
                                    class="size-4 text-coollabs dark:text-warning" />
                                Start Proxy
                            </x-forms.button>
                        @endif
                    </div>
                @endcan
            @endif
        </div>
        <div class="hidden lg:block lg:h-12" aria-hidden="true"></div>
    </div>

    @script
        <script>
            $wire.$on('checkProxyEvent', () => {
                try {
                    $wire.$call('checkProxy');
                } catch (error) {
                    console.error(error);
                    $wire.$dispatch('error', 'Failed to check proxy status. Please try again.');
                }
            });
            $wire.$on('restartEvent', () => {
                if ($wire.restartInitiated) return;
                window.dispatchEvent(new CustomEvent('startproxy'));
                $wire.$call('restart');
            });
            $wire.$on('startProxy', () => {
                window.dispatchEvent(new CustomEvent('startproxy'));
                $wire.$call('startProxy');
            });
            $wire.$on('stopEvent', () => {
                $wire.$call('stop');
            });
        </script>
    @endscript
</nav>
