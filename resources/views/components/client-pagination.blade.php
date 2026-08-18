@props([
    'summary',
    'pageSizeModel',
    'storageKey',
    'options' => [10, 25, 50, 100],
    'previousAction' => 'page = Math.max(1, page - 1)',
    'nextAction' => 'page = Math.min(totalPages, page + 1)',
    'previousDisabled' => 'page === 1',
    'nextDisabled' => 'page >= totalPages',
])

<footer {{ $attributes->class('flex min-h-11 items-center justify-between border-t border-neutral-200 px-4 text-[11px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint') }}>
    <div class="flex items-center gap-3">
        <span class="inline-flex h-7 items-center whitespace-nowrap tabular-nums" x-text="{{ $summary }}"></span>
        <x-page-size-select :model="$pageSizeModel" :storage-key="$storageKey" :options="$options" />
    </div>
    <div class="flex items-center gap-1">
        <button type="button" x-on:click="{{ $previousAction }}" x-bind:disabled="{{ $previousDisabled }}"
            class="flex size-7 items-center justify-center rounded-md border border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black disabled:pointer-events-none disabled:opacity-35 dark:border-white/[0.08] dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
            aria-label="Previous page">
            <x-reicon name="arrow-right" class="size-3.5 rotate-180" />
        </button>
        <button type="button" x-on:click="{{ $nextAction }}" x-bind:disabled="{{ $nextDisabled }}"
            class="flex size-7 items-center justify-center rounded-md border border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black disabled:pointer-events-none disabled:opacity-35 dark:border-white/[0.08] dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
            aria-label="Next page">
            <x-reicon name="arrow-right" class="size-3.5" />
        </button>
    </div>
</footer>
