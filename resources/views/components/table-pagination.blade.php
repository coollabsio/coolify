@props([
    'from' => 0,
    'to' => 0,
    'total' => 0,
    'currentPage' => 1,
    'lastPage' => 1,
    /** Comma-separated Livewire actions that should show the loading state */
    'wireTarget' => null,
    'firstAction' => null,
    'previousAction' => null,
    'nextAction' => null,
    'lastAction' => null,
])

@php
    $onFirstPage = (int) $currentPage <= 1;
    $onLastPage = (int) $currentPage >= (int) $lastPage;
    $buttonClass =
        'flex size-7 items-center justify-center rounded-md border border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black disabled:pointer-events-none disabled:opacity-35 dark:border-white/[0.08] dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg';
    $hasLoading = filled($wireTarget);
@endphp

<footer {{ $attributes->class('flex min-h-11 items-center justify-between border-t border-neutral-200 px-4 text-[11px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint') }}>
    <div class="flex items-center gap-3">
        <span class="inline-flex h-7 items-center whitespace-nowrap tabular-nums">{{ $from }}–{{ $to }} of {{ $total }}</span>
        @isset($pageSize)
            {{ $pageSize }}
        @endisset
        @if ($hasLoading)
            <span wire:loading.inline-flex wire:target="{{ $wireTarget }}" class="inline-flex items-center"
                aria-live="polite">
                <svg class="loading-indicator size-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none"
                    viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                    </path>
                </svg>
                <span class="sr-only">Loading page…</span>
            </span>
        @endif
    </div>
    <div class="flex items-center gap-1">
        <button type="button" class="{{ $buttonClass }}" aria-label="Previous page"
            @if (filled($previousAction)) wire:click="{{ $previousAction }}" @endif
            @if ($hasLoading) wire:loading.attr="disabled" wire:target="{{ $wireTarget }}" @endif
            @disabled($onFirstPage)>
            <x-reicon name="arrow-right" class="size-3.5 rotate-180" />
        </button>
        <button type="button" class="{{ $buttonClass }}" aria-label="Next page"
            @if (filled($nextAction)) wire:click="{{ $nextAction }}" @endif
            @if ($hasLoading) wire:loading.attr="disabled" wire:target="{{ $wireTarget }}" @endif
            @disabled($onLastPage)>
            <x-reicon name="arrow-right" class="size-3.5" />
        </button>
    </div>
</footer>
