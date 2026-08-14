<div @if ($type !== 'server') wire:init="loadContainers" @endif>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > Terminal | Coolify
    </x-slot>

    @if ($type === 'application')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <livewire:project.application.heading :application="$resource" wire:key="application-heading-command" />
    @elseif ($type === 'database')
        <livewire:project.database.heading :database="$resource" />
    @elseif ($type === 'service')
        <livewire:project.service.heading :service="$resource" :parameters="$parameters" title="Terminal" />
    @else
        <livewire:server.navbar :server="$servers->first()" />
    @endif

    @php
        $consoleUnavailable = ($type === 'server' && (! $servers->first()->isTerminalEnabled() || ! $servers->first()->isFunctional()))
            || ($type !== 'server' && $containersLoaded && $containers->isEmpty());
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
        $containerOptions = $containers->map(fn ($container) => [
            'value' => data_get($container, 'container.Names'),
            'label' => data_get($container, 'container.Names').' · '.data_get($container, 'server.name'),
        ])->values();
    @endphp

    @if (in_array($type, ['application', 'database', 'service', 'server'], true))
        <section class="application-settings-workspace mt-4 w-full max-w-[1180px] lg:mt-0">
            <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
                @if ($type === 'server')
                    <x-server.sidebar :server="$resource" activeMenu="terminal" />
                @elseif ($type === 'application')
                    <x-application.configuration-sidebar :application="$resource" current-route="project.application.command" />
                @elseif ($type === 'database')
                    <x-database.configuration-sidebar :database="$resource" current-route="project.database.command" />
                @else
                    <x-service.configuration-sidebar :service="$resource" current-route="project.service.command" />
                @endif
                <div class="min-w-0">
    @endif

    @if ($consoleUnavailable)
        <section class="mt-8 w-full max-w-[1180px] xl:mt-0">
            @if ($type === 'server')
                <x-empty size="lg" title="Terminal unavailable"
                    description="This server is not functional or terminal access is disabled."
                    icon-name="browser-terminal" />
            @else
                <x-empty size="lg" title="Terminal unavailable"
                    description="No containers are running, or terminal access is disabled on the destination server."
                    icon-name="browser-terminal" />
            @endif
        </section>
    @else
        <section class="mt-8 mb-0! h-[calc(100dvh-8rem)] min-h-[32rem] w-full max-w-[1180px] xl:mt-0"
            x-on:terminal-theme-selected="setTheme($event.detail.theme)"
            x-on:terminal-starting.window="syncTheme()"
            x-data="{
                themeKeys: @js($consoleThemeKeys),
                themeAccents: @js($consoleThemeAccents),
                consoleTheme: 'system',
                themeOpen: false,
                containerOpen: false,
                targetChosen: @js($selected_container !== 'default'),
                selectedContainer: @entangle('selected_container').live,
                containerOptions: @js($containerOptions),
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
                selectContainer(value) {
                    window.dispatchEvent(new CustomEvent('terminal-starting'));
                    this.selectedContainer = value;
                    this.targetChosen = true;
                    this.containerOpen = false;
                },
                get selectedContainerLabel() {
                    return this.containerOptions.find((option) => option.value === this.selectedContainer)?.label
                        ?? 'Choose a container';
                },
            }">
            <div data-terminal-session-canvas
                class="application-console-shell flex h-full min-h-0 flex-col overflow-hidden rounded-lg p-3 sm:p-6"
                :data-console-theme="consoleTheme"
                :style="{ '--terminal-scrollbar': themeAccents[consoleTheme] }">
                <header
                    class="terminal-session-toolbar absolute top-3 right-3 left-3 z-20 flex items-center gap-3 text-white select-none">
                    @if ($type === 'server')
                        <div class="terminal-session-target-trigger flex h-8 min-w-0 max-w-sm flex-1 items-center gap-2 rounded-md px-2.5 text-xs font-medium text-white/70"
                            x-data="{ autoConnected: false }"
                            @if ($servers->first()->isTerminalEnabled() && $servers->first()->isFunctional())
                                x-on:terminal-websocket-ready.window="if (!autoConnected) {
                                    autoConnected = true;
                                    $nextTick(() => $wire.dispatchSelf('connectToServer'));
                                }"
                            @endif>
                            <x-reicon name="browser-terminal" class="size-3.5 shrink-0 text-white/55" />
                            <span class="min-w-0 truncate font-semibold text-white/80">
                                {{ $servers->first()->name }}
                            </span>
                        </div>
                    @else
                        <div class="flex min-w-0 flex-1 items-center gap-2">
                            @if ($containers->count() === 1)
                                <div class="terminal-session-target-trigger flex h-8 min-w-0 max-w-sm items-center gap-2 rounded-md px-2.5 text-xs font-medium text-white/70">
                                <x-reicon name="browser-terminal" class="size-3.5 shrink-0 text-white/55" />
                                <span class="min-w-0 truncate font-semibold text-white/80">
                                    {{ data_get($containers->first(), 'container.Names') }}
                                    · {{ data_get($containers->first(), 'server.name') }}
                                </span>
                                </div>
                            @else
                                <div x-cloak x-show="targetChosen" class="relative min-w-0"
                                    @click.outside="containerOpen = false">
                                    <button type="button"
                                        class="terminal-session-target-trigger flex h-8 max-w-[34rem] min-w-48 items-center gap-2 rounded-md px-2.5 text-xs font-medium text-white/70 transition-colors hover:bg-white/[0.08] hover:text-white"
                                        @click="containerOpen = !containerOpen">
                                        <span class="min-w-0 flex-1 truncate text-left"
                                            x-text="selectedContainerLabel"></span>
                                        <svg class="size-2.5 shrink-0 text-white/35" viewBox="0 0 12 12"
                                            fill="none" aria-hidden="true">
                                            <path d="m3.5 4.75 2.5 2.5 2.5-2.5" stroke="currentColor"
                                                stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </button>
                                    <div x-cloak x-show="containerOpen" x-transition.origin.top.left
                                        class="terminal-target-picker terminal-target-list absolute top-11 left-0 z-50 max-h-72 w-80 overflow-y-auto rounded-lg border p-1 shadow-[0_18px_50px_rgba(0,0,0,0.35)]">
                                        <template x-for="option in containerOptions" :key="option.value">
                                            <button type="button"
                                                class="flex h-8 w-full items-center gap-2 rounded-md px-2 text-left text-[11px] text-white/65 transition-colors hover:bg-white/[0.07] hover:text-white"
                                                @click="selectContainer(option.value)">
                                                <span class="min-w-0 flex-1 truncate" x-text="option.label"></span>
                                                <svg x-show="option.value === selectedContainer"
                                                    class="size-3 shrink-0 text-[#fcd452]" viewBox="0 0 12 12"
                                                    fill="none" aria-hidden="true">
                                                    <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor"
                                                        stroke-width="1.4" stroke-linecap="round"
                                                        stroke-linejoin="round" />
                                                </svg>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif

                    <x-terminal.theme-selector :themes="$consoleThemes" :theme-names="$consoleThemeNames"
                        :theme-accents="$consoleThemeAccents" />
                </header>

                <div class="terminal-session-panel mt-8 flex min-h-0 flex-1 flex-col overflow-hidden">
                <div class="application-console-block min-h-0 flex-1">
                    @if ($type !== 'server' && $containers->count() > 1)
                        <div x-cloak x-show="!targetChosen" data-terminal-target-picker="launcher"
                            class="absolute inset-0 z-20 flex items-start justify-start p-6 sm:p-10">
                            <div class="terminal-target-picker w-full max-w-md rounded-lg border p-2 shadow-[0_18px_50px_rgba(0,0,0,0.28)]">
                                <div class="px-2 pt-1 pb-2">
                                    <div class="text-sm font-semibold text-white/80">Start a terminal session</div>
                                    <div class="mt-0.5 text-[11px] text-white/45">
                                    Choose a container to start a session
                                    </div>
                                </div>
                                <div class="max-h-64 overflow-y-auto">
                                    <template x-for="option in containerOptions" :key="option.value">
                                        <button type="button"
                                            class="flex h-9 w-full items-center gap-2 rounded-md px-2 text-left text-[11px] text-white/65 transition-colors hover:bg-white/[0.07] hover:text-white"
                                            @click="selectContainer(option.value)">
                                            <x-reicon name="layers" class="size-3.5 shrink-0 text-white/35" />
                                            <span class="min-w-0 flex-1 truncate" x-text="option.label"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    @endif
                    <livewire:project.shared.terminal variant="application"
                        :auto-start="$type === 'server' || ! $containersLoaded || $containers->count() === 1" />
                </div>
                </div>
            </div>
        </section>
    @endif
    @if (in_array($type, ['application', 'database', 'service', 'server'], true))
                </div>
            </div>
        </section>
    @endif
</div>
