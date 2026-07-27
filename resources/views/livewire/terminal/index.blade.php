<div class="application-settings-form w-full">
    <x-slot:title>
        Terminal | Coolify
    </x-slot>

    <header class="mb-5 flex items-center justify-between gap-4">
        <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">Terminal</h1>
    </header>

    <div x-init="$wire.loadContainers()">
        @if ($isLoadingContainers)
            <div
                class="flex min-h-80 items-center justify-center rounded-xl border border-neutral-200 bg-white dark:border-white/[0.08] dark:bg-white/[0.025]">
                <x-loading text="Loading servers and containers..." />
            </div>
        @elseif ($servers->isEmpty())
            <div
                class="flex min-h-80 flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-50 px-6 text-center dark:border-white/[0.1] dark:bg-white/[0.02]">
                <div
                    class="mb-4 flex size-11 items-center justify-center rounded-xl border border-neutral-200 bg-white text-neutral-400 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-faint">
                    <x-reicon name="browser-terminal" class="size-5" />
                </div>
                <h2 class="text-[15px] font-semibold">No terminal targets available</h2>
                <p class="mt-1 max-w-sm text-[13px] text-neutral-500 dark:text-fg-dim">
                    Connect a reachable server and enable terminal access to start a session.
                </p>
            </div>
        @else
            @php
                $terminalOptions = [
                    ['value' => 'default', 'label' => 'Select a server or container'],
                ];

                foreach ($servers as $server) {
                    $terminalOptions[] = [
                        'value' => $server->uuid,
                        'label' => $server->name.' · Server',
                    ];

                    foreach ($containers as $container) {
                        if ($container['server_uuid'] === $server->uuid) {
                            $terminalOptions[] = [
                                'value' => $container['uuid'],
                                'label' => $server->name.' · '.$container['name'],
                            ];
                        }
                    }
                }

                $terminalLabel = collect($terminalOptions)->firstWhere('value', $selected_uuid)['label']
                    ?? 'Select a server or container';

                $consoleThemes = [
                    ['key' => 'shadows-midnight', 'name' => "Shadow's Midnight", 'background' => 'linear-gradient(135deg, #2a3b4c, rgba(42, 59, 76, 0.4))', 'accent' => '#6d7a7c'],
                    ['key' => 'shadows-golden-hour', 'name' => "Shadow's Golden Hour", 'background' => 'linear-gradient(135deg, #d58a42, rgba(213, 138, 66, 0.4))', 'accent' => '#bf8c3c'],
                    ['key' => 'shadows-cosmic-purple', 'name' => "Shadow's Cosmic Purple", 'background' => 'linear-gradient(135deg, #5d3e66, rgba(93, 62, 102, 0.4))', 'accent' => '#A76DBE'],
                    ['key' => 'shadows-neon-glow', 'name' => "Shadow's Neon Glow", 'background' => 'linear-gradient(135deg, #f300a6, rgba(243, 0, 166, 0.3))', 'accent' => '#DB425A'],
                    ['key' => 'shadows-icy-mist', 'name' => "Shadow's Icy Mist", 'background' => 'linear-gradient(135deg, #d0d8e2, rgba(208, 216, 226, 0.2))', 'accent' => '#93b7c4'],
                    ['key' => 'shadows-tropical-storm', 'name' => "Shadow's Tropical Storm", 'background' => 'linear-gradient(135deg, #00b894, #1fa771, #2ecc71, #27ae60)', 'accent' => '#1fa771'],
                    ['key' => 'shadows-golden-nebula', 'name' => "Shadow's Golden Nebula", 'background' => 'linear-gradient(135deg, #ffd700, #ff6347, #d4a20e, #ffcc00, #1f3d6f)', 'accent' => '#d4a20e'],
                    ['key' => 'shadows-cosmic-lagoon', 'name' => "Shadow's Cosmic Lagoon", 'background' => 'linear-gradient(135deg, #1d2b64, #2f4f96, #00b5b8, #9c27b0, #8e24aa)', 'accent' => '#00b5b8'],
                    ['key' => 'shadows-neon-nebula', 'name' => "Shadow's Neon Nebula", 'background' => 'linear-gradient(135deg, #00d9d9, #ff55aa, #1e1e2f, #2f3b57, #ff99ff)', 'accent' => '#ff55aa'],
                    ['key' => 'shadows-transparent', 'name' => "Shadow's Blur Black", 'background' => 'rgba(0, 0, 0, 0.7)', 'accent' => '#8C8E9C'],
                ];
                $consoleThemeKeys = collect($consoleThemes)->pluck('key')->values();
                $consoleThemeNames = collect($consoleThemes)->pluck('name', 'key');
                $consoleThemeAccents = collect($consoleThemes)->pluck('accent', 'key');
            @endphp

            <section class="h-[calc(100dvh-11rem)] min-h-[32rem] w-full"
                x-data="{
                    targets: @js(array_slice($terminalOptions, 1)),
                    currentTargetLabel: @js($terminalLabel),
                    targetOpen: false,
                    targetSearch: '',
                    themeKeys: @js($consoleThemeKeys),
                    consoleTheme: 'shadows-cosmic-purple',
                    themeOpen: false,
                    get filteredTargets() {
                        const query = this.targetSearch.trim().toLowerCase();
                        return query
                            ? this.targets.filter((target) => target.label.toLowerCase().includes(query))
                            : this.targets;
                    },
                    init() {
                        const savedTheme = localStorage.getItem('coolify-console-theme');
                        this.consoleTheme = this.themeKeys.includes(savedTheme) ? savedTheme : 'shadows-cosmic-purple';
                        localStorage.setItem('coolify-console-theme', this.consoleTheme);
                    },
                    setTheme(theme) {
                        this.consoleTheme = theme;
                        this.themeOpen = false;
                        localStorage.setItem('coolify-console-theme', theme);
                        window.dispatchEvent(new CustomEvent('terminal-theme-change', { detail: { theme } }));
                    },
                    async selectTarget(target) {
                        this.currentTargetLabel = target.label;
                        this.targetOpen = false;
                        this.targetSearch = '';
                        await $wire.set('selected_uuid', target.value);
                        await $wire.connectToContainer();
                    }
                }">
                <div class="application-console-shell flex h-full min-h-0 flex-col overflow-hidden rounded-lg"
                    :data-console-theme="consoleTheme">
                    <header
                        class="application-console-header flex h-[30px] shrink-0 items-center border-b border-white/[0.12] px-2.5 text-[11px] text-white select-none">
                        <div class="relative flex min-w-0 flex-1 items-center"
                            x-on:click.outside="targetOpen = false">
                            <button type="button"
                                class="flex h-6 min-w-0 max-w-sm cursor-pointer items-center gap-2 rounded-md px-1.5 text-left transition-colors hover:bg-white/[0.08]"
                                x-on:click="targetOpen = !targetOpen" :aria-expanded="targetOpen"
                                aria-label="Choose terminal target">
                                <x-reicon name="browser-terminal" class="size-3.5 shrink-0 text-white/55" />
                                <span class="min-w-0 truncate text-[11px] font-semibold text-white/80"
                                    x-text="currentTargetLabel"></span>
                                <svg class="size-2.5 shrink-0 text-white/35" viewBox="0 0 12 12" fill="none"
                                    aria-hidden="true">
                                    <path d="m3.5 4.75 2.5 2.5 2.5-2.5" stroke="currentColor"
                                        stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>

                            <div x-cloak x-show="targetOpen" x-transition.origin.top.left
                                class="absolute top-7 left-0 z-50 w-72 overflow-hidden rounded-lg border border-white/[0.1] bg-[#111113] shadow-[0_18px_50px_rgba(0,0,0,0.55)]">
                                <div class="border-b border-white/[0.08] p-1.5">
                                    <div class="relative">
                                        <x-reicon name="search"
                                            class="pointer-events-none absolute top-1/2 left-2 size-3 -translate-y-1/2 text-white/35" />
                                        <input x-model.debounce.100ms="targetSearch" type="search"
                                            placeholder="Filter targets…"
                                            class="h-7! w-full rounded-md! border-white/[0.08]! bg-white/[0.05]! py-0! pr-2! pl-7! text-[11px]! text-white! shadow-none! placeholder:text-white/30 focus:border-white/[0.16]! focus:ring-0!">
                                    </div>
                                </div>
                                <div class="max-h-72 overflow-y-auto p-1">
                                    <template x-for="target in filteredTargets" :key="target.value">
                                        <button type="button"
                                            class="flex min-h-8 w-full cursor-pointer items-center gap-2 rounded-md px-2 text-left text-[11px] text-white/65 transition-colors hover:bg-white/[0.07] hover:text-white"
                                            x-on:click="selectTarget(target)">
                                            <x-reicon name="browser-terminal"
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
                                    <div x-show="filteredTargets.length === 0"
                                        class="px-2 py-5 text-center text-[11px] text-white/35">
                                        No matching targets
                                    </div>
                                </div>
                            </div>

                            <div class="hidden items-center gap-1.5 text-[10px] font-medium text-white/40"
                                wire:loading.flex wire:target="selected_uuid,connectToContainer">
                                <svg class="size-3 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle class="opacity-25" cx="12" cy="12" r="9"
                                        stroke="currentColor" stroke-width="3" />
                                    <path class="opacity-75" d="M21 12a9 9 0 0 0-9-9"
                                        stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                                </svg>
                                Connecting
                            </div>
                        </div>

                        <div class="relative ml-auto shrink-0" x-on:click.outside="themeOpen = false">
                            <button type="button"
                                class="flex h-6 cursor-pointer items-center gap-1.5 rounded-md px-2 text-[10px] font-medium text-white/55 transition-colors hover:bg-white/[0.08] hover:text-white/90"
                                x-on:click="themeOpen = !themeOpen" aria-label="Choose terminal theme"
                                :aria-expanded="themeOpen">
                                <span class="size-2 rounded-full ring-1 ring-white/20"
                                    :style="{ backgroundColor: @js($consoleThemeAccents)[consoleTheme] }"></span>
                                <span x-text="@js($consoleThemeNames)[consoleTheme]"></span>
                                <svg class="size-2.5 text-white/35" viewBox="0 0 12 12" fill="none"
                                    aria-hidden="true">
                                    <path d="m3.5 4.75 2.5 2.5 2.5-2.5" stroke="currentColor"
                                        stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>

                            <div x-cloak x-show="themeOpen" x-transition.origin.top.right
                                class="absolute top-7 right-0 z-50 max-h-80 w-56 overflow-y-auto rounded-lg border border-white/[0.1] bg-[#111113] p-1 shadow-[0_18px_50px_rgba(0,0,0,0.55)]">
                                @foreach ($consoleThemes as $theme)
                                    <button type="button"
                                        class="flex h-8 w-full cursor-pointer items-center gap-2 rounded-md px-2 text-left text-[11px] text-white/65 transition-colors hover:bg-white/[0.07] hover:text-white"
                                        x-on:click="setTheme('{{ $theme['key'] }}')">
                                        <span class="h-3 w-5 rounded-full border border-white/10"
                                            style="background: {{ $theme['background'] }}"></span>
                                        <span class="flex-1">{{ $theme['name'] }}</span>
                                        <svg x-show="consoleTheme === '{{ $theme['key'] }}'"
                                            class="size-3 text-[#fcd452]" viewBox="0 0 12 12" fill="none"
                                            aria-hidden="true">
                                            <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor"
                                                stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </header>

                    <div class="application-console-block min-h-0 flex-1">
                        <livewire:project.shared.terminal variant="application" />
                    </div>
                </div>
            </section>
        @endif
    </div>
</div>
