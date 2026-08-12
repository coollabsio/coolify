@php
    $consoleThemes = [
        ['key' => 'system', 'name' => 'System', 'background' => 'linear-gradient(135deg, #ffffff 0 50%, #121214 50% 100%)', 'accent' => '#8C8E9C'],
        ['key' => 'shadows-midnight', 'name' => 'Midnight', 'background' => 'linear-gradient(135deg, #2a3b4c, rgba(42, 59, 76, 0.4))', 'accent' => '#6d7a7c'],
        ['key' => 'shadows-golden-hour', 'name' => 'Golden Hour', 'background' => 'linear-gradient(135deg, #d58a42, rgba(213, 138, 66, 0.4))', 'accent' => '#bf8c3c'],
        ['key' => 'shadows-cosmic-purple', 'name' => 'Cosmic Purple', 'background' => 'linear-gradient(135deg, #5d3e66, rgba(93, 62, 102, 0.4))', 'accent' => '#A76DBE'],
        ['key' => 'shadows-neon-glow', 'name' => 'Neon Glow', 'background' => 'linear-gradient(135deg, #f300a6, rgba(243, 0, 166, 0.3))', 'accent' => '#DB425A'],
        ['key' => 'shadows-icy-mist', 'name' => 'Icy Mist', 'background' => 'linear-gradient(135deg, #d0d8e2, rgba(208, 216, 226, 0.2))', 'accent' => '#93b7c4'],
        ['key' => 'shadows-tropical-storm', 'name' => 'Tropical Storm', 'background' => 'linear-gradient(135deg, #00b894, #1fa771, #2ecc71, #27ae60)', 'accent' => '#1fa771'],
        ['key' => 'shadows-golden-nebula', 'name' => 'Golden Nebula', 'background' => 'linear-gradient(135deg, #ffd700, #ff6347, #d4a20e, #ffcc00, #1f3d6f)', 'accent' => '#d4a20e'],
        ['key' => 'shadows-cosmic-lagoon', 'name' => 'Cosmic Lagoon', 'background' => 'linear-gradient(135deg, #1d2b64, #2f4f96, #00b5b8, #9c27b0, #8e24aa)', 'accent' => '#00b5b8'],
        ['key' => 'shadows-neon-nebula', 'name' => 'Neon Nebula', 'background' => 'linear-gradient(135deg, #00d9d9, #ff55aa, #1e1e2f, #2f3b57, #ff99ff)', 'accent' => '#ff55aa'],
        ['key' => 'shadows-transparent', 'name' => 'Blur Black', 'background' => 'rgba(0, 0, 0, 0.7)', 'accent' => '#8C8E9C'],
    ];
    $consoleThemeKeys = collect($consoleThemes)->pluck('key')->values();
    $consoleThemeNames = collect($consoleThemes)->pluck('name', 'key');
    $consoleThemeAccents = collect($consoleThemes)->pluck('accent', 'key');

    $terminalOptions = [];
    if (! $isLoadingContainers && $servers->isNotEmpty()) {
        foreach ($servers as $server) {
            $terminalOptions[] = [
                'value' => $server->uuid,
                'label' => $server->name.' · Server',
                'name' => $server->name,
                'server' => $server->name,
                'type' => 'server',
            ];

            foreach ($containers as $container) {
                if ($container['server_uuid'] === $server->uuid) {
                    $terminalOptions[] = [
                        'value' => $container['uuid'],
                        'label' => $server->name.' · '.$container['name'],
                        'name' => $container['name'],
                        'server' => $server->name,
                        'type' => 'container',
                    ];
                }
            }
        }
    }

    $terminalLabel = collect($terminalOptions)->firstWhere('value', $selected_uuid)['label']
        ?? 'Select a server or container';
@endphp

<div class="{{ $selected_uuid === 'default' ? '' : 'terminal-page' }} application-settings-form"
    x-init="$wire.loadContainers()">
    <x-slot:title>
        Terminal | Coolify
    </x-slot>

    <header class="terminal-page-header shrink-0">
        <div class="flex items-center gap-2">
            <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">Terminal</h1>
            <x-helper
                helper="If you cannot connect, confirm the server is reachable and that the terminal port is open on the firewall.<br><br><a class='underline' href='https://coolify.io/docs/knowledge-base/server/firewall/#terminal' target='_blank' rel='noopener noreferrer'>Documentation</a>" />
        </div>
        <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
            Run commands on reachable servers and containers from the browser.
        </p>
    </header>

    <section class="{{ $selected_uuid === 'default' ? 'w-full' : 'terminal-page-console min-h-0 w-full flex-1 overflow-hidden' }}"
        x-on:terminal-theme-selected="setTheme($event.detail.theme)"
        x-on:terminal-starting.window="syncTheme()"
        x-data="{
            targets: @js($terminalOptions),
            multipleServers: @js($servers->count() > 1),
            currentTargetLabel: @js($terminalLabel),
            targetChosen: @js($selected_uuid !== 'default'),
            targetOpen: false,
            targetSearch: '',
            themeKeys: @js($consoleThemeKeys),
            themeAccents: @js($consoleThemeAccents),
            consoleTheme: 'system',
            themeOpen: false,
            get filteredTargetGroups() {
                const query = this.targetSearch.trim().toLowerCase();
                const targets = query
                    ? this.targets.filter((target) => target.label.toLowerCase().includes(query))
                    : this.targets;

                return [
                    { type: 'server', label: 'Servers', targets: targets.filter((target) => target.type === 'server') },
                    { type: 'container', label: 'Containers', targets: targets.filter((target) => target.type === 'container') },
                ].filter((group) => group.targets.length > 0);
            },
            init() {
                const savedTheme = localStorage.getItem('coolify-console-theme');
                this.consoleTheme = this.themeKeys.includes(savedTheme) ? savedTheme : 'system';
                localStorage.setItem('coolify-console-theme', this.consoleTheme);
            },
            setTheme(theme) {
                this.consoleTheme = theme;
                this.themeOpen = false;
                localStorage.setItem('coolify-console-theme', theme);
                window.dispatchEvent(new CustomEvent('terminal-theme-change', { detail: { theme } }));
            },
            syncTheme() {
                const savedTheme = localStorage.getItem('coolify-console-theme');
                this.consoleTheme = this.themeKeys.includes(savedTheme) ? savedTheme : 'system';
            },
            async selectTarget(target) {
                window.dispatchEvent(new CustomEvent('terminal-starting'));
                this.currentTargetLabel = target.label;
                this.targetChosen = true;
                this.targetOpen = false;
                this.targetSearch = '';
                await $wire.set('selected_uuid', target.value);
            }
        }">
        @if ($selected_uuid === 'default')
            <div wire:key="terminal-target-canvas" data-terminal-target-canvas
                class="application-settings-workspace flex w-full min-w-0 flex-col">
                <x-application.settings-section class="terminal-target-card" data-terminal-target-picker="page"
                    title="Start a terminal session" flush>
                    @if (! $isLoadingContainers && $servers->isNotEmpty())
                        <x-slot:actions>
                            <div class="relative w-full sm:w-64">
                                <x-reicon name="search"
                                    class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                                <input x-model.debounce.100ms="targetSearch" type="search" placeholder="Filter targets"
                                    aria-label="Filter terminal targets"
                                    class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                                <button x-cloak x-show="targetSearch" x-on:click="targetSearch = ''" type="button"
                                    class="absolute top-1/2 right-2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
                                    aria-label="Clear target filter">
                                    <span class="text-sm leading-none">×</span>
                                </button>
                            </div>
                        </x-slot:actions>
                    @endif

                    @if ($isLoadingContainers)
                        <div class="flex min-h-40 items-center justify-center gap-2 text-[13px] text-neutral-500 dark:text-fg-dim">
                            <x-loading class="size-4" />
                            <span>Finding available servers and containers…</span>
                        </div>
                    @elseif ($servers->isEmpty())
                        <x-empty title="No terminal targets available"
                            description="Connect a reachable server and enable terminal access to start a session."
                            icon-name="browser-terminal" />
                    @else
                        <div class="terminal-target-card-list">
                            <template x-for="group in filteredTargetGroups" :key="group.type">
                                <section class="command-palette-section">
                                    <div class="terminal-target-group-label">
                                        <span x-text="group.label"></span>
                                        <span class="terminal-target-group-count" x-text="group.targets.length"></span>
                                    </div>
                                    <template x-for="target in group.targets" :key="target.value">
                                        <button type="button" class="command-palette-item cursor-pointer"
                                            x-on:click="selectTarget(target)">
                                            <x-reicon name="servers" x-cloak x-show="target.type === 'server'"
                                                class="size-3.5 shrink-0 text-neutral-400 dark:text-fg-faint" />
                                            <x-reicon name="layers" x-cloak x-show="target.type === 'container'"
                                                class="size-3.5 shrink-0 text-neutral-400 dark:text-fg-faint" />
                                            <span class="command-palette-item-name min-w-0 flex-1"
                                                x-text="target.name"></span>
                                            <span x-cloak x-show="multipleServers"
                                                class="terminal-target-item-server" x-text="target.server"></span>
                                            <x-reicon name="arrow-right" class="command-palette-item-chevron" />
                                        </button>
                                    </template>
                                </section>
                            </template>
                            <div x-show="filteredTargetGroups.length === 0"
                                class="px-3 py-8 text-center text-[13px] text-neutral-500 dark:text-fg-dim">
                                No matching targets
                            </div>
                        </div>
                    @endif
                </x-application.settings-section>
            </div>
        @else
        <div wire:key="terminal-session-canvas" data-terminal-session-canvas
            class="application-console-shell flex h-full min-h-0 flex-col overflow-hidden rounded-lg p-3 sm:p-6"
            :data-console-theme="consoleTheme"
            :style="{ '--terminal-scrollbar': themeAccents[consoleTheme] }">
            <header
                class="terminal-session-toolbar absolute top-3 right-3 left-3 z-20 flex items-center gap-3 text-white select-none">
                <div class="relative flex min-w-0 flex-1 items-center gap-2"
                    x-on:click.outside="targetOpen = false">
                    @if ($isLoadingContainers)
                        <span class="min-w-0 truncate text-[11px] font-semibold text-white/55">
                            Loading targets…
                        </span>
                        <svg class="size-3 shrink-0 animate-spin text-white/40" viewBox="0 0 24 24" fill="none"
                            aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor"
                                stroke-width="3" />
                            <path class="opacity-75" d="M21 12a9 9 0 0 0-9-9" stroke="currentColor"
                                stroke-width="3" stroke-linecap="round" />
                        </svg>
                    @elseif ($servers->isEmpty())
                        <x-reicon name="browser-terminal" class="size-3.5 shrink-0 text-white/55" />
                        <span class="min-w-0 truncate text-[11px] font-semibold text-white/55">
                            No terminal targets
                        </span>
                    @else
                        <button type="button"
                            x-cloak x-show="targetChosen"
                            class="terminal-session-target-trigger flex h-8 min-w-0 max-w-sm cursor-pointer items-center gap-2 rounded-md px-2.5 text-left text-xs font-medium text-white/70 transition-colors hover:bg-white/[0.08] hover:text-white"
                            x-on:click="targetOpen = !targetOpen" :aria-expanded="targetOpen"
                            aria-label="Choose terminal target">
                            <span class="min-w-0 truncate text-[11px] font-semibold text-white/80"
                                x-text="currentTargetLabel"></span>
                            <svg class="size-2.5 shrink-0 text-white/35" viewBox="0 0 12 12" fill="none"
                                aria-hidden="true">
                                <path d="m3.5 4.75 2.5 2.5 2.5-2.5" stroke="currentColor" stroke-width="1.25"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>

                        <div x-cloak x-show="targetOpen" x-transition.origin.top.left
                            class="terminal-target-picker absolute top-11 left-0 z-50 w-80 overflow-hidden rounded-lg border shadow-[0_18px_50px_rgba(0,0,0,0.35)]">
                            <div class="border-b border-white/[0.08] p-1.5">
                                <div class="relative">
                                    <x-reicon name="search"
                                        class="pointer-events-none absolute top-1/2 left-2 size-3 -translate-y-1/2 text-white/35" />
                                    <input x-model.debounce.100ms="targetSearch" type="search"
                                        placeholder="Filter targets…"
                                        class="h-7! w-full rounded-md! border-white/[0.08]! bg-white/[0.05]! py-0! pr-2! pl-7! text-[11px]! text-white! shadow-none! placeholder:text-white/30 focus:border-accent! focus:ring-0!">
                                </div>
                            </div>
                            <div class="terminal-target-list max-h-72 overflow-y-auto p-1">
                                <template x-for="group in filteredTargetGroups" :key="group.type">
                                    <section class="not-last:mb-1">
                                        <div class="px-2 py-1.5 text-[9px] font-semibold tracking-wider text-white/35 uppercase"
                                            x-text="group.label"></div>
                                        <template x-for="target in group.targets" :key="target.value">
                                            <button type="button"
                                                class="flex min-h-8 w-full cursor-pointer items-center gap-2 rounded-md px-2 text-left text-[11px] text-white/65 transition-colors hover:bg-white/[0.07] hover:text-white"
                                                x-on:click="selectTarget(target)">
                                                <x-reicon name="servers" x-cloak x-show="target.type === 'server'"
                                                    class="size-3.5 shrink-0 text-white/35" />
                                                <x-reicon name="layers" x-cloak x-show="target.type === 'container'"
                                                    class="size-3.5 shrink-0 text-white/35" />
                                                <span class="min-w-0 flex-1 truncate" x-text="target.label"></span>
                                                <svg x-show="$wire.selected_uuid === target.value"
                                                    class="size-3 text-[#fcd452]" viewBox="0 0 12 12" fill="none"
                                                    aria-hidden="true">
                                                    <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor"
                                                        stroke-width="1.4" stroke-linecap="round"
                                                        stroke-linejoin="round" />
                                                </svg>
                                            </button>
                                        </template>
                                    </section>
                                </template>
                                <div x-show="filteredTargetGroups.length === 0"
                                    class="px-2 py-5 text-center text-[11px] text-white/35">
                                    No matching targets
                                </div>
                            </div>
                        </div>

                    @endif
                </div>

                <x-terminal.theme-selector :themes="$consoleThemes" :theme-names="$consoleThemeNames"
                        :theme-accents="$consoleThemeAccents" />
            </header>

            <div class="terminal-session-panel mt-8 flex min-h-0 flex-1 flex-col overflow-hidden">
            <div class="application-console-block min-h-0 flex-1 overflow-hidden">
                @if ($isLoadingContainers)
                    <div class="flex h-full min-h-0 items-center justify-center">
                        <div class="terminal-loading-label flex items-center gap-2">
                            <svg class="size-3 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor"
                                    stroke-width="3" />
                                <path class="opacity-75" d="M21 12a9 9 0 0 0-9-9" stroke="currentColor"
                                    stroke-width="3" stroke-linecap="round" />
                            </svg>
                            <span>Loading servers and containers…</span>
                        </div>
                    </div>
                @elseif ($servers->isEmpty())
                    <div class="flex h-full min-h-0 items-center justify-center bg-[#141414] px-4">
                        <x-empty size="lg" title="No terminal targets available"
                            description="Connect a reachable server and enable terminal access to start a session."
                            icon-name="browser-terminal" />
                    </div>
                @else
                    <div x-cloak x-show="!targetChosen" data-terminal-target-picker="launcher"
                        class="absolute inset-0 z-20 flex items-start justify-start p-6 sm:p-10">
                        <div class="terminal-target-picker w-full max-w-md overflow-hidden rounded-lg border shadow-[0_18px_50px_rgba(0,0,0,0.28)]">
                            <div class="border-b border-white/[0.08] p-2">
                                <div class="px-1 pb-2">
                                    <div class="text-sm font-semibold text-white/80">Start a terminal session</div>
                                    <div class="mt-0.5 text-[11px] text-white/45">Choose a server or container</div>
                                </div>
                                <div class="relative">
                                    <x-reicon name="search"
                                        class="pointer-events-none absolute top-1/2 left-2 size-3 -translate-y-1/2 text-white/35" />
                                    <input x-model.debounce.100ms="targetSearch" type="search"
                                        placeholder="Filter targets…"
                                        class="h-8! w-full rounded-md! border-white/[0.08]! bg-white/[0.05]! py-0! pr-2! pl-7! text-[11px]! text-white! shadow-none! placeholder:text-white/30 focus:border-accent! focus:ring-0!">
                                </div>
                            </div>
                            <div class="terminal-target-list max-h-72 overflow-y-auto p-1">
                                <template x-for="group in filteredTargetGroups" :key="group.type">
                                    <section class="not-last:mb-1">
                                        <div class="px-2 py-1.5 text-[9px] font-semibold tracking-wider text-white/35 uppercase"
                                            x-text="group.label"></div>
                                        <template x-for="target in group.targets" :key="target.value">
                                            <button type="button"
                                                class="group flex min-h-9 w-full cursor-pointer items-center gap-2 rounded-md px-2 text-left text-[11px] text-white/65 transition-colors hover:bg-white/[0.07] hover:text-white"
                                                x-on:click="selectTarget(target)">
                                                <x-reicon name="servers" x-show="target.type === 'server'"
                                                    class="size-3.5 shrink-0 text-white/35" />
                                                <x-reicon name="layers" x-show="target.type === 'container'"
                                                    class="size-3.5 shrink-0 text-white/35" />
                                                <span class="min-w-0 flex-1 truncate" x-text="target.label"></span>
                                                <x-reicon name="arrow-right"
                                                    class="size-3 shrink-0 text-white/40 opacity-0 transition-opacity group-hover:opacity-100" />
                                            </button>
                                        </template>
                                    </section>
                                </template>
                                <div x-show="filteredTargetGroups.length === 0"
                                    class="px-2 py-5 text-center text-[11px] text-white/35">
                                    No matching targets
                                </div>
                            </div>
                        </div>
                    </div>
                    <livewire:project.shared.terminal variant="application" />
                @endif
            </div>
            </div>
        </div>
        @endif
    </section>
</div>
