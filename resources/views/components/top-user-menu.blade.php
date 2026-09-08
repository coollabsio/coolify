@props([
    'sidebar' => false,
])

@php
    $user = auth()->user();
    $userName = $user?->name ?? 'Account';
    $userEmail = $user?->email ?? '';
    $userInitial = strtoupper(mb_substr($user?->name ?: ($user?->email ?: 'A'), 0, 1));
@endphp
<div @class(['relative', 'min-w-0' => $sidebar]) x-data="{
    open: false,
    appearanceOpen: false,
    theme: localStorage.getItem('theme') === 'purple' ? 'custom' : (localStorage.getItem('theme') || 'dark'),
    pageWidth: localStorage.getItem('pageWidth') || 'full',
    themeColor: localStorage.getItem('themeColor') || '#6b16ed',
    themeColorFrame: null,
    avatarUrl: @js($user?->avatar_path ? profile_avatar_url($user) : null),
    openPanel() {
        this.appearanceOpen = false;
        this.open = true;
    },
    closePanel() {
        this.open = false;
    },
    setTheme(type, closeMenu = true) {
        this.theme = type;
        localStorage.setItem('theme', type);

        if (closeMenu) {
            this.closePanel();
        }

        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const isDark = type === 'dark' || type === 'custom' || (type === 'system' && prefersDark);
        document.documentElement.classList.toggle('dark', isDark);
        document.documentElement.dataset.theme = type === 'custom' ? 'custom' : (isDark ? 'dark' : 'light');
        document.documentElement.style.setProperty('--theme-base-color', localStorage.themeColor || '#6b16ed');
        document.documentElement.style.setProperty('--theme-accent-foreground', window.themeAccentForeground(this.themeColor));
        document.querySelector('meta[name=theme-color]')?.setAttribute('content', isDark ? '#101010' : '#ffffff');
    },
    setWidth(width) {
        this.pageWidth = width;
        localStorage.setItem('pageWidth', width);
        window.dispatchEvent(new CustomEvent('page-width-changed', { detail: width }));
    },
    previewThemeColor(color) {
        this.themeColor = color;

        if (this.theme !== 'custom') {
            this.theme = 'custom';
            document.documentElement.classList.add('dark');
            document.documentElement.dataset.theme = 'custom';
        }

        if (this.themeColorFrame) {
            return;
        }

        this.themeColorFrame = requestAnimationFrame(() => {
            document.documentElement.style.setProperty('--theme-base-color', this.themeColor);
            document.documentElement.style.setProperty('--theme-accent-foreground', window.themeAccentForeground(this.themeColor));
            this.themeColorFrame = null;
        });
    },
    saveThemeColor(color) {
        this.previewThemeColor(color);
        localStorage.setItem('themeColor', color);
        localStorage.setItem('theme', 'custom');
    },
}" @avatar-updated.window="avatarUrl = $event.detail.url" @keydown.escape.window="closePanel()"
    @click.outside="closePanel()">
    <button type="button" @click="open ? closePanel() : openPanel()"
        title="{{ $userName }}" aria-label="Account menu for {{ $userName }}"
        @if ($sidebar) :class="collapsed && 'w-8 justify-center px-0'" @endif
        @class([
            'flex h-8 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-100 px-2 shadow-sm transition-colors hover:bg-neutral-200 dark:border-white/[0.08] dark:bg-white/[0.06] dark:hover:bg-white/[0.1]',
            'max-w-36' => $sidebar,
        ])>
        <img x-cloak x-show="avatarUrl" :src="avatarUrl" alt="{{ $userName }}"
            class="size-5 shrink-0 rounded-full object-cover">
        <span x-show="!avatarUrl"
            class="flex size-5 shrink-0 items-center justify-center rounded-full bg-neutral-200 text-[11px] font-semibold text-neutral-700 dark:bg-white/[0.1] dark:text-fg">
            {{ $userInitial }}
        </span>
        @if ($sidebar)
            <span class="min-w-0 truncate text-xs font-medium" :class="collapsed && 'hidden'">{{ $userName }}</span>
        @endif
        <svg class="size-3.5 shrink-0 text-neutral-400 dark:text-fg-faint transition-transform"
            :class="[open && 'rotate-180', {{ $sidebar ? "collapsed && 'hidden'" : 'false' }}]"
            viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </button>

    <div x-show="open" x-cloak @class([
            'top-user-menu-panel listbox-panel z-[90]! max-h-none! w-52! min-w-0! overflow-visible! animate-in fade-in zoom-in-95 duration-150',
            'right-0! left-auto!' => ! $sidebar,
            'bottom-full! left-0! right-auto! top-auto! mb-1!' => $sidebar,
            'origin-bottom-left' => $sidebar,
            'origin-top-right' => ! $sidebar,
        ])>
        <div class="min-w-0 px-2 py-1.5">
            <div class="truncate text-[13px] font-semibold text-black dark:text-fg">{{ $userName }}</div>
            <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $userEmail }}</div>
        </div>
        <div class="my-1 h-px bg-neutral-200 dark:bg-white/[0.07]"></div>

        <a href="{{ route('profile') }}" {{ wireNavigate() }} class="listbox-option">
            <span class="flex items-center gap-2">
                <x-reicon name="profile" class="size-4 opacity-80" />
                Profile
            </span>
        </a>
        <button type="button" class="listbox-option w-full" @click="appearanceOpen = !appearanceOpen"
            :aria-expanded="appearanceOpen">
            <span class="flex items-center gap-2">
                <x-reicon name="settings" class="size-4 opacity-80" />
                Appearance
            </span>
            <svg class="size-3.5 text-neutral-400 transition-transform dark:text-fg-faint"
                :class="appearanceOpen && 'rotate-180'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" />
            </svg>
        </button>
        <div x-show="appearanceOpen" x-collapse class="mx-1 grid gap-0.5 pb-1 pl-6">
            @foreach ([
                ['value' => 'light', 'label' => 'Light'],
                ['value' => 'system', 'label' => 'System'],
                ['value' => 'dark', 'label' => 'Dark'],
                ['value' => 'custom', 'label' => 'Custom'],
            ] as $option)
                @if ($option['value'] === 'custom')
                    <div
                        class="relative flex h-8 w-full items-center justify-between rounded-md px-2 text-left text-xs text-neutral-600 transition-colors hover:bg-neutral-200 hover:text-neutral-950 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                        <span class="flex items-center gap-2">
                            <span class="size-3.5 rounded-full border border-white/20"
                                :style="`background: ${themeColor}`"></span>
                            Custom
                        </span>
                        <svg x-show="theme === 'custom'" class="size-3.5 text-coollabs dark:text-warning"
                            viewBox="0 0 12 12" fill="none" aria-hidden="true">
                            <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor" stroke-width="1.4"
                                stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <input type="color" :value="themeColor" @input="previewThemeColor($event.target.value)"
                            @change="saveThemeColor($event.target.value)"
                            aria-label="Custom theme color"
                            class="absolute inset-0 z-10 h-full w-full cursor-pointer opacity-0" />
                    </div>
                @else
                    <button type="button" @click="setTheme('{{ $option['value'] }}')"
                        class="flex h-8 w-full items-center justify-between rounded-md px-2 text-left text-xs text-neutral-600 transition-colors hover:bg-neutral-200 hover:text-neutral-950 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                        <span>{{ $option['label'] }}</span>
                        <svg x-show="theme === '{{ $option['value'] }}'"
                            class="size-3.5 text-coollabs dark:text-warning" viewBox="0 0 12 12" fill="none"
                            aria-hidden="true">
                            <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor" stroke-width="1.4"
                                stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                @endif
            @endforeach
            <div class="my-1 h-px bg-neutral-200 dark:bg-white/[0.07]"></div>
            <div class="px-2 pt-1 pb-0.5 text-[10px] font-medium tracking-wide text-neutral-400 uppercase dark:text-fg-faint">
                Page width
            </div>
            @foreach ([
                ['value' => 'full', 'label' => 'Full width'],
                ['value' => 'centered', 'label' => 'Centered'],
            ] as $option)
                <button type="button" @click="setWidth('{{ $option['value'] }}')"
                    class="flex h-8 w-full items-center justify-between rounded-md px-2 text-left text-xs text-neutral-600 transition-colors hover:bg-neutral-200 hover:text-neutral-950 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                    <span>{{ $option['label'] }}</span>
                    <svg x-show="pageWidth === '{{ $option['value'] }}'"
                        class="size-3.5 text-coollabs dark:text-warning" viewBox="0 0 12 12" fill="none"
                        aria-hidden="true">
                        <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor" stroke-width="1.4"
                            stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
            @endforeach
        </div>

        <div class="my-1 h-px bg-neutral-200 dark:bg-white/[0.07]"></div>

        <livewire:settings-dropdown trigger="account-menu" />
        <a href="https://coolify.io/docs" target="_blank" rel="noopener noreferrer" class="listbox-option">
            <span class="flex items-center gap-2">
                <x-reicon name="documentation" class="size-4 opacity-80" />
                Documentation
            </span>
        </a>
        <x-modal-input title="How can we help?">
            <x-slot:content>
                <div class="listbox-option cursor-pointer" @click="closePanel()">
                    <span class="flex items-center gap-2">
                        <x-reicon name="feedback" class="size-4 opacity-80" />
                        Feedback
                    </span>
                </div>
            </x-slot:content>
            <livewire:help />
        </x-modal-input>
        @if (isSubscribed() || !isCloud())
            <a href="https://coolify.io/sponsorships" target="_blank" rel="noopener noreferrer"
                class="listbox-option">
                <span class="flex items-center gap-2">
                    <x-reicon name="sponsor" class="size-4 text-pink-500" />
                    Sponsor us
                </span>
            </a>
        @endif

        <div class="my-1 h-px bg-neutral-200 dark:bg-white/[0.07]"></div>

        <form action="/logout" method="POST">
            @csrf
            <button type="submit" class="listbox-option w-full text-left text-error dark:text-error">
                <span class="flex items-center gap-2">
                    <x-reicon name="logout" class="size-4 opacity-90" />
                    Log out
                </span>
            </button>
        </form>
    </div>
</div>
