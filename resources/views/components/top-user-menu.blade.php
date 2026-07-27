@php
    $user = auth()->user();
    $userName = $user?->name ?? 'Account';
    $userEmail = $user?->email ?? '';
@endphp
<div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
    <button type="button" @click="open = !open" @click.outside="open = false"
        class="flex h-8 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-100 px-3 shadow-sm transition-colors hover:bg-neutral-200 dark:border-white/[0.08] dark:bg-white/[0.06] dark:hover:bg-white/[0.1]">
        <span class="hidden sm:block max-w-[9rem] truncate text-[12px] font-medium text-black dark:text-fg">{{ $userName }}</span>
        <svg class="size-3.5 shrink-0 text-neutral-400 dark:text-fg-faint transition-transform" :class="open && 'rotate-180'"
            viewBox="0 0 24 24" fill="none">
            <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.120ms
        class="listbox-panel right-0! left-auto! z-[90]! max-h-none! w-52! min-w-0! overflow-visible!">
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
        <a href="{{ route('profile.appearance') }}" {{ wireNavigate() }} class="listbox-option">
            <span class="flex items-center gap-2">
                <x-reicon name="settings" class="size-4 opacity-80" />
                Appearance
            </span>
        </a>

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
                <div class="listbox-option cursor-pointer" @click="open = false">
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
