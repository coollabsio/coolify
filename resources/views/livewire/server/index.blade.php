<div class="application-settings-form w-full">
    <x-slot:title>
        Servers | Coolify
    </x-slot>

    <div class="mb-5 flex items-center justify-between gap-4">
        <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">Servers</h1>
        @can('createAnyResource')
            <a href="{{ route('server.create') }}" {{ wireNavigate() }}
                class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                <x-reicon name="plus" class="size-3.5" />
                New server
            </a>
        @endcan
    </div>

    @php
        $serverRows = $servers->map(function ($server) {
            $isReady = $server->settings->is_reachable
                && $server->settings->is_usable
                && ! $server->settings->force_disabled;

            return [
                'uuid' => $server->uuid,
                'name' => $server->name,
                'description' => $server->description ?: 'No description',
                'address' => $server->ip,
                'href' => route('server.show', ['server_uuid' => $server->uuid]),
                'status' => $isReady ? 'Ready' : ($server->settings->force_disabled ? 'Disabled' : 'Validation required'),
                'statusType' => $isReady ? 'success' : 'error',
                'ready' => $isReady,
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
                [server.name, server.description, server.address, server.status]
                    .some(value => String(value || '').toLowerCase().includes(query))
            );
        },
        setViewMode(mode) {
            this.viewMode = mode;
            localStorage.setItem('coolify-servers-view', mode);
        }
    }">
        @if ($servers->isEmpty())
            <div
                class="flex min-h-80 flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-50 px-6 text-center dark:border-white/[0.1] dark:bg-white/[0.02]">
                <div
                    class="mb-4 flex size-11 items-center justify-center rounded-xl border border-neutral-200 bg-white text-neutral-400 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-faint">
                    <x-reicon name="servers" class="size-5" />
                </div>
                <h2 class="text-[15px] font-semibold">No servers yet</h2>
                <p class="mt-1 max-w-sm text-[13px] text-neutral-500 dark:text-fg-dim">
                    Add a server to deploy applications, databases, and services.
                </p>
            </div>
        @else
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full sm:max-w-sm">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input x-model.debounce.150ms="search" type="search" placeholder="Search servers"
                        class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-neutral-300! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
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
                        class="flex h-8 items-center rounded-lg border border-neutral-200 bg-white p-0.5 dark:border-white/[0.08] dark:bg-white/[0.035]">
                        <button type="button" x-on:click="setViewMode('table')"
                            class="flex size-6.5 items-center justify-center rounded-md transition-colors"
                            :class="viewMode === 'table'
                                ? 'bg-coollabs/10 text-coollabs dark:bg-warning/15 dark:text-warning'
                                : 'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'"
                            aria-label="Table view">
                            <x-reicon name="unordered-list" class="size-3.5" />
                        </button>
                        <button type="button" x-on:click="setViewMode('grid')"
                            class="flex size-6.5 items-center justify-center rounded-md transition-colors"
                            :class="viewMode === 'grid'
                                ? 'bg-coollabs/10 text-coollabs dark:bg-warning/15 dark:text-warning'
                                : 'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'"
                            aria-label="Grid view">
                            <x-reicon name="grid" class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>

            <div x-cloak x-show="viewMode === 'grid'"
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <template x-for="server in filteredServers" :key="server.uuid">
                    <a :href="server.href" {{ wireNavigate() }}
                        class="group flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                <x-reicon name="servers" class="size-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h2 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg"
                                    x-text="server.name"></h2>
                                <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint"
                                    x-text="server.address"></p>
                            </div>
                        </div>
                        <div class="mt-auto flex items-center justify-between gap-2 pt-4">
                            <x-status-badge dynamic>
                                <span class="size-1.5 rounded-full"
                                    :class="server.ready ? 'bg-emerald-500' : 'bg-red-500'"></span>
                                <span x-text="server.status"></span>
                            </x-status-badge>
                            <x-reicon name="arrow-right"
                                class="size-3.5 text-neutral-400 transition-transform group-hover:translate-x-0.5 dark:text-fg-faint" />
                        </div>
                    </a>
                </template>
            </div>

            <div x-show="viewMode === 'table'"
                class="overflow-x-auto rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
                <div
                    class="grid min-w-[680px] grid-cols-[minmax(0,1fr)_minmax(10rem,.7fr)_9.5rem_2rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                    <div>Server</div>
                    <div>Address</div>
                    <div>Status</div>
                    <div></div>
                </div>
                <template x-for="server in filteredServers" :key="server.uuid">
                    <a :href="server.href" {{ wireNavigate() }}
                        class="grid min-h-14 min-w-[680px] grid-cols-[minmax(0,1fr)_minmax(10rem,.7fr)_9.5rem_2rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-dim">
                                <x-reicon name="servers" class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-[13px] font-semibold text-black dark:text-fg"
                                    x-text="server.name"></p>
                                <p class="truncate text-[11px] text-neutral-500 dark:text-fg-faint"
                                    x-text="server.description"></p>
                            </div>
                        </div>
                        <div class="truncate text-neutral-500 dark:text-fg-dim" x-text="server.address"></div>
                        <div>
                            <x-status-badge dynamic>
                                <span class="size-1.5 rounded-full"
                                    :class="server.ready ? 'bg-emerald-500' : 'bg-red-500'"></span>
                                <span x-text="server.status"></span>
                            </x-status-badge>
                        </div>
                        <x-reicon name="arrow-right"
                            class="size-3.5 justify-self-end text-neutral-400 dark:text-fg-faint" />
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
