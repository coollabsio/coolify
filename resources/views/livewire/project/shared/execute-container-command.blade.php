<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > Console | Coolify
    </x-slot>

    @if ($type === 'application')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <livewire:project.application.heading :application="$resource" />
    @elseif ($type === 'database')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <livewire:project.database.heading :database="$resource" />
    @elseif ($type === 'service')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <livewire:project.service.heading :service="$resource" :parameters="$parameters" title="Console" />
    @else
        <livewire:server.navbar :server="$servers->first()" />
    @endif

    @php
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
        $containerOptions = $containers->map(fn ($container) => [
            'value' => data_get($container, 'container.Names'),
            'label' => data_get($container, 'container.Names').' · '.data_get($container, 'server.name'),
        ])->values();
    @endphp

    <section class="mt-8 mb-0! h-[calc(100dvh-8rem)] min-h-[32rem] w-full max-w-[1180px] xl:mt-0"
        x-data="{
            themeKeys: @js($consoleThemeKeys),
            consoleTheme: 'shadows-cosmic-purple',
            themeOpen: false,
            containerOpen: false,
            selectedContainer: @entangle('selected_container').live,
            containerOptions: @js($containerOptions),
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
            selectContainer(value) {
                this.selectedContainer = value;
                this.containerOpen = false;
            },
            get selectedContainerLabel() {
                return this.containerOptions.find((option) => option.value === this.selectedContainer)?.label
                    ?? 'Choose a container';
            },
        }">
        <div class="application-console-shell flex h-full min-h-0 flex-col overflow-hidden rounded-lg"
            :data-console-theme="consoleTheme">
            <header
                class="application-console-header flex h-[30px] shrink-0 items-center border-b border-white/[0.12] px-2.5 text-[11px] text-white select-none">
                @if ($type === 'server')
                    <div class="flex min-w-0 flex-1 items-center gap-2" x-data="{ autoConnected: false }"
                        @if ($servers->first()->isTerminalEnabled() && $servers->first()->isFunctional())
                            x-on:terminal-websocket-ready.window="if (!autoConnected) {
                                autoConnected = true;
                                $nextTick(() => $wire.dispatchSelf('connectToServer'));
                            }"
                        @endif>
                        <x-reicon name="browser-terminal" class="size-3.5 shrink-0 text-white/55" />
                        <span class="min-w-0 truncate text-[11px] font-semibold text-white/80">
                            {{ $servers->first()->name }}
                        </span>
                        <div class="hidden items-center gap-1.5 text-[10px] font-medium text-white/40"
                            wire:loading.flex wire:target="connectToServer">
                            <x-loading />
                            Connecting
                        </div>
                    </div>
                @elseif ($containers->isNotEmpty())
                    <div class="flex min-w-0 flex-1 items-center gap-2" x-data="{ autoConnected: false }"
                        x-on:terminal-websocket-ready.window="if ({{ $containers->count() }} === 1 && !autoConnected) {
                            autoConnected = true;
                            $nextTick(() => $wire.dispatchSelf('connectToContainer'));
                        }">
                        <x-reicon name="browser-terminal" class="size-3.5 shrink-0 text-white/55" />
                        @if ($containers->count() === 1)
                            <span class="min-w-0 truncate text-[11px] font-semibold text-white/80">
                                {{ data_get($containers->first(), 'container.Names') }}
                                · {{ data_get($containers->first(), 'server.name') }}
                            </span>
                        @else
                            <div class="relative min-w-0" @click.outside="containerOpen = false">
                                <button type="button"
                                    class="flex h-6 max-w-[34rem] min-w-48 items-center gap-1.5 rounded-md px-2 text-[11px] font-semibold text-white/80 transition-colors hover:bg-white/[0.08]"
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
                                    class="absolute top-7 left-0 z-50 max-h-72 w-80 overflow-y-auto rounded-lg border border-white/[0.1] bg-[#111113] p-1 shadow-[0_18px_50px_rgba(0,0,0,0.55)]">
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
                        <div class="hidden items-center gap-1.5 text-[10px] font-medium text-white/40"
                            wire:loading.flex wire:target="selected_container,connectToContainer">
                            <x-loading />
                            Connecting
                        </div>
                    </div>
                @else
                    <div class="flex min-w-0 flex-1 items-center gap-2 text-white/60">
                        <x-reicon name="browser-terminal" class="size-3.5 shrink-0" />
                        <span class="truncate font-semibold">No running containers</span>
                    </div>
                @endif

                <div class="relative ml-auto shrink-0" @click.outside="themeOpen = false">
                    <button type="button"
                        class="flex h-6 items-center gap-1.5 rounded-md px-2 text-[10px] font-medium text-white/55 transition-colors hover:bg-white/[0.08] hover:text-white/90"
                        @click="themeOpen = !themeOpen" aria-label="Choose terminal theme">
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
                                class="flex h-8 w-full items-center gap-2 rounded-md px-2 text-left text-[11px] text-white/65 transition-colors hover:bg-white/[0.07] hover:text-white"
                                @click="setTheme('{{ $theme['key'] }}')">
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
                @if ($type === 'server' && (! $servers->first()->isTerminalEnabled() || ! $servers->first()->isFunctional()))
                    <div class="flex h-full items-center justify-center bg-[#141414]">
                        <x-empty size="lg" title="Console unavailable"
                            description="This server is not functional or terminal access is disabled.">
                            <x-slot:icon>
                                <x-reicon name="browser-terminal" class="size-9" />
                            </x-slot:icon>
                        </x-empty>
                    </div>
                @elseif ($type !== 'server' && $containers->isEmpty())
                    <div class="flex h-full items-center justify-center bg-[#141414]">
                        <x-empty size="lg" title="Console unavailable"
                            description="No containers are running, or terminal access is disabled on the destination server.">
                            <x-slot:icon>
                                <x-reicon name="browser-terminal" class="size-9" />
                            </x-slot:icon>
                        </x-empty>
                    </div>
                @else
                    <livewire:project.shared.terminal variant="application" />
                @endif
            </div>
        </div>
    </section>
</div>
