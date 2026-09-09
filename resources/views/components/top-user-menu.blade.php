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
    avatarUrl: @js($user?->avatar_path ? profile_avatar_url($user) : null),
    openPanel() {
        this.appearanceOpen = false;
        this.open = true;
    },
    closePanel() {
        this.open = false;
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

    <div x-show="open" x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
        @class([
            'top-user-menu-panel listbox-panel z-[90]! max-h-none! w-52! min-w-0! overflow-visible!',
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
        <div x-show="appearanceOpen" x-collapse.duration.200ms class="mx-1 pb-1 pl-6">
            <x-theme-controls variant="menu" />
        </div>

        <button type="button" class="listbox-option w-full" @click="toggleAutoCollapse()"
            :aria-pressed="autoCollapse" title="Collapse the sidebar on pages that have a settings menu">
            <span class="flex items-center gap-2">
                <svg class="size-4 opacity-80" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <rect x="3" y="4" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.6" />
                    <path d="M9 4v16" stroke="currentColor" stroke-width="1.6" />
                </svg>
                Auto-collapse sidebar
            </span>
            <svg x-show="autoCollapse" x-cloak class="size-4 shrink-0 text-black dark:text-fg" viewBox="0 0 24 24"
                fill="none" aria-hidden="true">
                <path d="M5 12l5 5L20 7" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" />
            </svg>
        </button>

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
