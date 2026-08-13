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
    $navButtonClass =
        'flex w-10 items-center justify-center border-r border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:border-white/[0.10] dark:text-fg-dim dark:hover:bg-white/[0.06]';
    $lastNavButtonClass =
        'flex w-10 items-center justify-center text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:text-fg-dim dark:hover:bg-white/[0.06]';
    $hasLoading = filled($wireTarget);
@endphp

<div {{ $attributes->class('flex items-center justify-between gap-3 px-4 py-3') }}>
    <p class="shrink-0 whitespace-nowrap text-[13px] text-neutral-500 dark:text-fg-dim">
        Showing <span class="tabular-nums text-black dark:text-fg">{{ $from }}–{{ $to }}</span>
        of <span class="tabular-nums text-black dark:text-fg">{{ $total }}</span>
    </p>
    <div class="inline-flex h-8 overflow-hidden rounded-lg border border-neutral-200 dark:border-white/[0.10]">
        <button type="button" class="{{ $navButtonClass }}" aria-label="First page" title="First page"
            @if (filled($firstAction)) wire:click="{{ $firstAction }}" @endif
            @if ($hasLoading) wire:loading.attr="disabled" wire:target="{{ $wireTarget }}" @endif
            @disabled($onFirstPage)>
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M18 6L12 12L18 18M11 6L5 12L11 18" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
        <button type="button" class="{{ $navButtonClass }}" aria-label="Previous page" title="Previous page"
            @if (filled($previousAction)) wire:click="{{ $previousAction }}" @endif
            @if ($hasLoading) wire:loading.attr="disabled" wire:target="{{ $wireTarget }}" @endif
            @disabled($onFirstPage)>
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M15 6L9 12L15 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round" />
            </svg>
        </button>
        <span
            class="relative flex min-w-12 items-center justify-center border-r border-neutral-200 px-3 text-[13px] tabular-nums text-black dark:border-white/[0.10] dark:text-fg">
            @if ($hasLoading)
                <span wire:loading.remove wire:target="{{ $wireTarget }}">{{ $currentPage }}</span>
                <span wire:loading.inline-flex wire:target="{{ $wireTarget }}" class="inline-flex items-center"
                    aria-live="polite">
                    <svg class="loading-indicator size-3.5 animate-spin"
                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                            stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                    <span class="sr-only">Loading page…</span>
                </span>
            @else
                {{ $currentPage }}
            @endif
        </span>
        <button type="button" class="{{ $navButtonClass }}" aria-label="Next page" title="Next page"
            @if (filled($nextAction)) wire:click="{{ $nextAction }}" @endif
            @if ($hasLoading) wire:loading.attr="disabled" wire:target="{{ $wireTarget }}" @endif
            @disabled($onLastPage)>
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M9 6L15 12L9 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round" />
            </svg>
        </button>
        <button type="button" class="{{ $lastNavButtonClass }}" aria-label="Last page" title="Last page"
            @if (filled($lastAction)) wire:click="{{ $lastAction }}" @endif
            @if ($hasLoading) wire:loading.attr="disabled" wire:target="{{ $wireTarget }}" @endif
            @disabled($onLastPage)>
            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M6 6L12 12L6 18M13 6L19 12L13 18" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
    </div>
</div>
