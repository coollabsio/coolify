@props(['text' => null, 'compact' => false])

<div {{ $attributes->merge(['class' => 'inline-flex items-center justify-center gap-2 text-[13px] text-neutral-500 dark:text-fg-dim']) }}
    role="status" aria-live="polite">
    <svg @class(['loading-indicator shrink-0 animate-spin', 'size-3' => $compact, 'size-4' => ! $compact]) viewBox="0 0 24 24"
        fill="none" aria-hidden="true">
        <circle class="opacity-20" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" />
        <path class="opacity-80" d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" />
    </svg>

    @if (isset($text))
        <span>{{ $text }}</span>
    @endif
</div>
