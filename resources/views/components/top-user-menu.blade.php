@php
    $user = auth()->user();
    $userName = $user?->name ?? 'Account';
    $userEmail = $user?->email ?? '';
    $userInitial = strtoupper(mb_substr($user?->name ?: ($user?->email ?: 'A'), 0, 1));
@endphp
<div class="relative" x-data="{
    open: false,
    appearanceOpen: false,
    theme: localStorage.getItem('theme') || 'dark',
    setTheme(type) {
        this.theme = type;
        localStorage.setItem('theme', type);

        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const isDark = type === 'dark' || (type === 'system' && prefersDark);
        document.documentElement.classList.toggle('dark', isDark);
        document.documentElement.dataset.theme = isDark ? 'dark' : 'light';
        document.querySelector('meta[name=theme-color]')?.setAttribute('content', isDark ? '#101010' : '#ffffff');
    },
}" @keydown.escape.window="open = false; appearanceOpen = false"
    @click.outside="open = false; appearanceOpen = false">
    <button type="button" @click="open = !open"
        title="{{ $userName }}" aria-label="Account menu for {{ $userName }}"
        class="flex h-8 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-100 px-2 shadow-sm transition-colors hover:bg-neutral-200 dark:border-white/[0.08] dark:bg-white/[0.06] dark:hover:bg-white/[0.1]">
        <span
            class="flex size-5 shrink-0 items-center justify-center rounded-full bg-neutral-200 text-[11px] font-semibold text-neutral-700 dark:bg-white/[0.1] dark:text-fg">
            {{ $userInitial }}
        </span>
        <svg class="size-3.5 shrink-0 text-neutral-400 dark:text-fg-faint transition-transform" :class="open && 'rotate-180'"
            viewBox="0 0 24 24" fill="none" aria-hidden="true">
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
            ] as $option)
                <button type="button" @click="setTheme('{{ $option['value'] }}')"
                    class="flex h-8 w-full items-center justify-between rounded-md px-2 text-left text-xs text-neutral-600 transition-colors hover:bg-neutral-200 hover:text-neutral-950 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                    <span>{{ $option['label'] }}</span>
                    <svg x-show="theme === '{{ $option['value'] }}'" class="size-3.5 text-coollabs dark:text-warning"
                        viewBox="0 0 12 12" fill="none" aria-hidden="true">
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
