@props(['text' => null])
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center gap-1.5']) }}>
    <svg class="size-3.5 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" />
        <path class="opacity-75" d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3"
            stroke-linecap="round" />
    </svg>
    @if (isset($text))
        <span>{{ $text }}</span>
    @endif
</span>
