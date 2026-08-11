{{--
    Sentry-style play/pause control for realtime analytics refresh. Sits next to the
    time-range selector. Live is only meaningful at the 24h range (minute-level rollups),
    so the control is disabled for 7d/30d. The choice is persisted per-browser in
    localStorage. Expects `$range` in scope and a `live` bool + `toggleLive()` on the host
    Livewire component. The pulsing dot respects prefers-reduced-motion.
--}}
@php $liveEnabled = $range === '24h'; @endphp
<div x-data="{ live: $wire.entangle('live').live }"
    x-init="
        if (localStorage.getItem('traffic-live') === '0') { live = false; }
        $watch('live', value => localStorage.setItem('traffic-live', value ? '1' : '0'));
    ">
    <button type="button"
        @click="live = !live"
        @disabled(! $liveEnabled)
        title="{{ $liveEnabled ? 'Toggle realtime refresh (updates every 60s)' : 'Realtime refresh is only available for the 24h range' }}"
        class="inline-flex h-7 items-center gap-1.5 rounded-md px-2.5 text-[12px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40 ring-1 ring-neutral-200 dark:ring-white/[0.08]"
        :class="live
            ? 'bg-white text-black shadow-sm dark:bg-white/[0.09] dark:text-fg'
            : 'text-neutral-500 hover:text-black dark:text-fg-faint dark:hover:text-fg'">
        <span x-show="live" class="relative flex h-1.5 w-1.5 shrink-0" aria-hidden="true">
            <span class="absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75 motion-safe:animate-ping"></span>
            <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
        </span>
        <svg x-show="!live" class="h-3 w-3 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M8 5v14l11-7z" />
        </svg>
        <span x-text="live ? 'Live' : 'Paused'">Live</span>
    </button>
</div>
