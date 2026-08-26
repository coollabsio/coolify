<div wire:init="refreshVitals" wire:poll.15s="refreshVitals"
    class="hidden min-[1440px]:flex min-w-0 shrink-0 items-center gap-1.5 text-[10.5px] text-neutral-600 dark:text-fg-dim"
    aria-label="Local host resource usage"
    title="Local host resources · red = used · green = free or available · refreshes every 15 seconds">
    <div class="flex shrink-0 items-center gap-1.5 rounded-lg border border-neutral-200 bg-neutral-50/70 px-2 py-1 dark:border-white/[0.06] dark:bg-white/[0.03]"
        title="CPU across {{ $metrics['cpu']['cores'] }} logical cores">
        <span class="font-semibold text-neutral-700 dark:text-fg">CPU</span>
        <span class="tabular-nums text-neutral-500 dark:text-fg-faint">{{ $metrics['cpu']['cores'] }}C</span>
        <span class="text-neutral-300 dark:text-white/15">·</span>
        @if ($metrics['cpu']['used_percent'] === null)
            <span class="tabular-nums text-neutral-400 dark:text-fg-faint">--</span>
        @else
            <span class="tabular-nums font-semibold text-red-600 dark:text-red-400">{{ number_format($metrics['cpu']['used_percent'], 1) }}%</span>
            <span class="text-neutral-300 dark:text-white/15">·</span>
            <span class="tabular-nums font-semibold text-emerald-600 dark:text-emerald-400">{{ number_format($metrics['cpu']['free_percent'], 1) }}%</span>
        @endif
    </div>

    <div class="flex shrink-0 items-center gap-1.5 rounded-lg border border-neutral-200 bg-neutral-50/70 px-2 py-1 dark:border-white/[0.06] dark:bg-white/[0.03]"
        title="RAM: used, available and total capacity">
        <span class="font-semibold text-neutral-700 dark:text-fg">RAM</span>
        <span class="tabular-nums font-semibold text-red-600 dark:text-red-400">{{ number_format($metrics['ram']['used_gib'], 1) }}G ({{ number_format($metrics['ram']['used_percent'], 1) }}%)</span>
        <span class="text-neutral-300 dark:text-white/15">·</span>
        <span class="tabular-nums font-semibold text-emerald-600 dark:text-emerald-400">{{ number_format($metrics['ram']['available_gib'], 1) }}G ({{ number_format($metrics['ram']['available_percent'], 1) }}%)</span>
        <span class="hidden 2xl:inline text-neutral-300 dark:text-white/15">·</span>
        <span class="hidden 2xl:inline tabular-nums text-neutral-500 dark:text-fg-faint">{{ number_format($metrics['ram']['total_gib'], 1) }}G</span>
    </div>

    <div class="flex shrink-0 items-center gap-1.5 rounded-lg border border-neutral-200 bg-neutral-50/70 px-2 py-1 dark:border-white/[0.06] dark:bg-white/[0.03]"
        title="Root filesystem: used, free and total capacity">
        <span class="font-semibold text-neutral-700 dark:text-fg">DISK</span>
        <span class="tabular-nums font-semibold text-red-600 dark:text-red-400">{{ number_format($metrics['disk']['used_gib'], 1) }}G ({{ number_format($metrics['disk']['used_percent'], 1) }}%)</span>
        <span class="text-neutral-300 dark:text-white/15">·</span>
        <span class="tabular-nums font-semibold text-emerald-600 dark:text-emerald-400">{{ number_format($metrics['disk']['free_gib'], 1) }}G ({{ number_format($metrics['disk']['free_percent'], 1) }}%)</span>
        <span class="hidden 2xl:inline text-neutral-300 dark:text-white/15">·</span>
        <span class="hidden 2xl:inline tabular-nums text-neutral-500 dark:text-fg-faint">{{ number_format($metrics['disk']['total_gib'], 1) }}G</span>
    </div>

    <div class="hidden min-[1900px]:flex shrink-0 items-center gap-1.5 rounded-lg border border-neutral-200 bg-neutral-50/70 px-2 py-1 dark:border-white/[0.06] dark:bg-white/[0.03]"
        title="Linux load averages for 1, 5 and 15 minutes">
        <span class="font-semibold text-neutral-700 dark:text-fg">LOAD</span>
        <span class="tabular-nums">{{ number_format($metrics['load']['one'], 2) }}</span>
        <span class="text-neutral-300 dark:text-white/15">·</span>
        <span class="tabular-nums">{{ number_format($metrics['load']['five'], 2) }}</span>
        <span class="text-neutral-300 dark:text-white/15">·</span>
        <span class="tabular-nums">{{ number_format($metrics['load']['fifteen'], 2) }}</span>
    </div>
</div>
