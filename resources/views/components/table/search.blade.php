@props([
    'placeholder' => 'Search',
    'loadingTarget' => null,
    'disabled' => false,
    'clearAction' => null,
    'clearWhen' => null,
])

<div class="table-search relative min-w-0 w-full">
    <input type="search" placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}"
        {{ $attributes->class(['input w-full pl-8!'])->except(['loading-target']) }} @disabled($disabled) />
    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5">
        @if ($loadingTarget)
            <x-reicon name="search" class="size-3.5 text-neutral-400 dark:text-fg-faint"
                wire:loading.remove wire:target="{{ $loadingTarget }}" />
            <svg wire:loading wire:target="{{ $loadingTarget }}" aria-hidden="true"
                class="size-3.5 animate-spin text-neutral-400 dark:text-fg-dim" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor"
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
            </svg>
        @else
            <x-reicon name="search" class="size-3.5 text-neutral-400 dark:text-fg-faint" />
        @endif
    </div>
    @if ($clearAction && $clearWhen)
        <button x-cloak x-show="{{ $clearWhen }}" x-on:click="{{ $clearAction }}" type="button"
            class="absolute top-1/2 right-2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
            aria-label="Clear search">
            <x-reicon name="x" class="size-3" />
        </button>
    @endif
</div>
