<div class="application-settings-form w-full">
    <x-slot:title>
        Servers | Coolify
    </x-slot>

    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h1 class="min-w-0 text-[24px]! leading-7! font-semibold! tracking-tight!">Servers</h1>
        <div class="flex flex-wrap items-center gap-2">
            @if (isDev())
                @can('create', App\Models\Server::class)
                    <a href="{{ route('server.transfer.import') }}" {{ wireNavigate() }}
                        class="button w-fit shrink-0 whitespace-nowrap">
                        <x-reicon name="upload" class="size-3.5" />
                        Import transfer
                        <x-status-badge label="Dev" />
                    </a>
                @endcan
            @endif
            @can('createAnyResource')
                <a href="{{ route('server.create') }}" {{ wireNavigate() }}
                    class="button w-fit shrink-0 whitespace-nowrap button-highlighted">
                    <x-reicon name="plus" class="size-3.5" />
                    New server
                </a>
            @endcan
        </div>
    </div>

    @php
        $serverRows = $servers->map(function ($server) {
            $isTransferredAway = $server->isTransferredAway();
            $isReady = $server->settings->is_reachable
                && $server->settings->is_usable
                && ! $server->settings->force_disabled
                && ! $isTransferredAway;
            $proxyNeedsAttention = $isReady && $server->proxySet()
                && ($server->proxy->status !== 'running' || $server->hasCurrentTraefikOutdatedInfo());
            $sentinelNeedsAttention = $isReady && $server->isSentinelEnabled() && ! $server->isSentinelLive();

            $status = match (true) {
                $isTransferredAway => 'Transferred away',
                $server->settings->force_disabled => 'Disabled',
                $proxyNeedsAttention || $sentinelNeedsAttention => 'Attention required',
                $isReady => 'Ready',
                default => 'Validation required',
            };

            $statusType = match (true) {
                $proxyNeedsAttention || $sentinelNeedsAttention => 'warning',
                $isReady => 'success',
                $isTransferredAway || $server->settings->force_disabled => 'error',
                default => 'error',
            };

            return [
                'uuid' => $server->uuid,
                'name' => $server->name,
                'description' => $server->description ?: 'No description',
                'href' => route('server.show', ['server_uuid' => $server->uuid]),
                'status' => $status,
                'statusType' => $statusType,
            ];
        })->values();
    @endphp

    <div x-data="{
        search: '',
        viewMode: localStorage.getItem('coolify-servers-view') || 'table',
        servers: @js($serverRows),
        get filteredServers() {
            const query = this.search.trim().toLowerCase();
            if (!query) return this.servers;
            return this.servers.filter(server =>
                [server.name, server.description, server.status]
                    .some(value => String(value || '').toLowerCase().includes(query))
            );
        },
        setViewMode(mode) {
            this.viewMode = mode;
            localStorage.setItem('coolify-servers-view', mode);
        }
    }">
        @if ($servers->isEmpty())
            <x-empty title="No servers yet"
                description="Add a server to deploy applications, databases, and services."
                icon-name="servers" />
        @else
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full sm:max-w-sm">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input x-model.debounce.150ms="search" type="search" placeholder="Search servers"
                        class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                    <button x-cloak x-show="search" x-on:click="search = ''" type="button"
                        class="absolute top-1/2 right-2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
                        aria-label="Clear search">
                        <x-reicon name="x" class="size-3" />
                    </button>
                </div>

                <div class="flex items-center gap-3">
                    <span class="text-[11px] text-neutral-500 dark:text-fg-faint">
                        <span x-text="filteredServers.length"></span>
                        <span x-text="filteredServers.length === 1 ? 'server' : 'servers'"></span>
                    </span>
                    <div
                        class="flex h-9 items-center rounded-lg border border-neutral-200 bg-white p-0.5 dark:border-white/[0.08] dark:bg-white/[0.035]">
                        <button type="button" x-on:click="setViewMode('table')"
                            class="flex size-7.5 items-center justify-center rounded-md transition-colors"
                            :class="viewMode === 'table'
                                ? 'control-selected'
                                : 'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'"
                            aria-label="Table view">
                            <x-reicon name="unordered-list" class="size-3.5" />
                        </button>
                        <button type="button" x-on:click="setViewMode('grid')"
                            class="flex size-7.5 items-center justify-center rounded-md transition-colors"
                            :class="viewMode === 'grid'
                                ? 'control-selected'
                                : 'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'"
                            aria-label="Grid view">
                            <x-reicon name="grid" class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>

            <div x-cloak x-show="viewMode === 'grid'"
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($servers as $server)
                    @php
                        $serverRow = $serverRows->firstWhere('uuid', $server->uuid);
                    @endphp
                    <a x-cloak
                        x-show="filteredServers.some(server => server.uuid === @js($server->uuid))"
                        href="{{ $serverRow['href'] }}" {{ wireNavigate() }}
                        class="group relative flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                        @if ($server->isMetricsEnabled())
                            <livewire:dashboard.server-metrics-chart :server="$server"
                                :key="'server-index-metrics-'.$server->uuid" />
                        @endif

                        <div class="relative z-10 flex items-start gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.1] dark:bg-white/[0.04] dark:text-fg-dim">
                                <x-reicon name="servers" class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h2 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                    {{ $serverRow['name'] }}
                                </h2>
                                <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                    {{ $serverRow['description'] }}
                                </p>
                            </div>
                            @if ($serverRow['statusType'] !== 'success')
                                <span data-tooltip="{{ $serverRow['status'] }}"
                                    aria-label="Server status: {{ $serverRow['status'] }}"
                                    @class([
                                        'ml-auto flex size-6 shrink-0 items-center justify-center rounded-md',
                                        'text-orange-500 dark:text-warning' => $serverRow['statusType'] === 'warning',
                                        'text-red-500 dark:text-red-400' => $serverRow['statusType'] === 'error',
                                    ])>
                                    <x-reicon name="alert-triangle" class="size-4" />
                                </span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>

            <div x-show="viewMode === 'table'"
                class="overflow-x-auto rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
                <div
                    class="grid min-w-[480px] grid-cols-[minmax(0,1fr)_9.5rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                    <div>Server</div>
                    <div>Status</div>
                </div>
                <template x-for="server in filteredServers" :key="server.uuid">
                    <a :href="server.href" {{ wireNavigate() }}
                        class="grid min-h-14 min-w-[480px] grid-cols-[minmax(0,1fr)_9.5rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.1] dark:bg-white/[0.035] dark:text-fg-dim">
                                <x-reicon name="servers" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-[13px] font-semibold text-black dark:text-fg"
                                    x-text="server.name"></p>
                                <p class="truncate text-[11px] text-neutral-500 dark:text-fg-faint"
                                    x-text="server.description"></p>
                            </div>
                            <span x-show="server.statusType !== 'success'" :data-tooltip="server.status"
                                :aria-label="`Server status: ${server.status}`"
                                class="ml-auto flex size-6 shrink-0 items-center justify-center rounded-md"
                                :class="server.statusType === 'warning' ? 'text-orange-500 dark:text-warning' : 'text-red-500 dark:text-red-400'">
                                <x-reicon name="alert-triangle" class="size-4" />
                            </span>
                        </div>
                        <div class="text-[11px] font-medium text-neutral-600 dark:text-fg-dim">
                            <span x-text="server.status"></span>
                        </div>
                    </a>
                </template>
            </div>

            <div x-show="filteredServers.length === 0"
                class="flex min-h-52 flex-col items-center justify-center rounded-xl border border-neutral-200 bg-white px-6 text-center dark:border-white/[0.08] dark:bg-white/[0.025]">
                <x-reicon name="search" class="mb-3 size-6 text-neutral-300 dark:text-fg-faint" />
                <p class="text-[13px] font-medium">No matching servers</p>
                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">Try a different search.</p>
            </div>
        @endif

        @isset($error)
            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-[12px] text-red-700 dark:border-red-500/20 dark:bg-red-500/[0.06] dark:text-red-300">
                {{ $error }}
            </div>
        @endisset
    </div>
</div>
