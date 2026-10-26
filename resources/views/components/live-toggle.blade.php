{{--
    Live refresh toggle. Entangles the `live` bool of the host Livewire component, so it works
    with any time range. The button shows "Live" with a pulsing dot when refresh is on and
    "Paused" with a play icon when it is off. `storageKey` keeps the choice per browser.
--}}
@props([
    'active' => false,
    'storageKey' => null,
    'title' => 'Turn live refresh on or off',
])

<div x-data="{ live: $wire.entangle('live').live }"
    @if ($storageKey) x-init="
        const stored = localStorage.getItem(@js($storageKey));
        if (stored === '0') { live = false; }
        if (stored === '1') { live = true; }
        $watch('live', value => localStorage.setItem(@js($storageKey), value ? '1' : '0'));
    " @endif>
    <button type="button" @click="live = !live" :aria-pressed="live ? 'true' : 'false'"
        aria-pressed="{{ $active ? 'true' : 'false' }}" title="{{ $title }}"
        class="inline-flex h-7 items-center gap-1.5 rounded-md px-2.5 text-[12px] font-medium ring-1 ring-neutral-200 transition-colors dark:ring-white/[0.08]"
        :class="live
            ? 'bg-white text-black shadow-sm dark:bg-white/[0.09] dark:text-fg'
            : 'text-neutral-500 hover:text-black dark:text-fg-faint dark:hover:text-fg'">
        <span x-show="live" class="relative flex h-1.5 w-1.5 shrink-0" @style(['display: none' => ! $active]) aria-hidden="true">
            <span class="absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75 motion-safe:animate-ping"></span>
            <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
        </span>
        <svg x-show="!live" class="h-3 w-3 shrink-0" @style(['display: none' => $active]) viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M8 5.5v13a1 1 0 0 0 1.5.86l11-6.5a1 1 0 0 0 0-1.72l-11-6.5A1 1 0 0 0 8 5.5Z" />
        </svg>
        <span x-text="live ? 'Live' : 'Paused'">{{ $active ? 'Live' : 'Paused' }}</span>
    </button>
</div>
