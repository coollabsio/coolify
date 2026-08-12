{{--
    "Live Refresh" toggle for realtime analytics. Sits next to the time-range selector.
    Realtime is only meaningful at the 24h range (minute-level rollups), so the control
    is hidden entirely for 7d/30d. The label is constant; the active state is shown by the
    button color + a pulsing emerald dot. The choice is persisted per-browser in
    localStorage. Expects `$range` in scope and a `live` bool on the host Livewire component.
    The pulse respects prefers-reduced-motion.
--}}
@if ($range === '24h')
    <div x-data="{ live: $wire.entangle('live').live }"
        x-init="
            if (localStorage.getItem('traffic-live') === '0') { live = false; }
            $watch('live', value => localStorage.setItem('traffic-live', value ? '1' : '0'));
        ">
        <button type="button"
            @click="live = !live"
            :aria-pressed="live ? 'true' : 'false'"
            title="Toggle realtime refresh (updates every 60s)"
            class="inline-flex h-7 items-center gap-1.5 rounded-md px-2.5 text-[12px] font-medium ring-1 transition-colors"
            :class="live
                ? 'bg-white text-black shadow-sm ring-neutral-200 dark:bg-white/[0.09] dark:text-fg dark:ring-white/[0.08]'
                : 'text-neutral-500 ring-neutral-200 hover:text-black dark:text-fg-faint dark:ring-white/[0.08] dark:hover:text-fg'">
            <span class="relative flex h-1.5 w-1.5 shrink-0" aria-hidden="true">
                <span x-show="live" class="absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75 motion-safe:animate-ping"></span>
                <span class="relative inline-flex h-1.5 w-1.5 rounded-full" :class="live ? 'bg-emerald-500' : 'bg-neutral-400 dark:bg-white/40'"></span>
            </span>
            <span>Live Refresh</span>
        </button>
    </div>
@endif
