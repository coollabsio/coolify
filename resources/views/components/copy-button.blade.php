@props([
    'value',
    'label' => 'Copy to clipboard',
])

<button type="button"
    x-data="{ copied: false }"
    x-on:click.prevent.stop="await window.copyToClipboard({{ Js::from($value) }}); copied = true; setTimeout(() => copied = false, 1000)"
    {{ $attributes->class('inline-flex size-6 shrink-0 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black disabled:pointer-events-none disabled:opacity-40 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-white') }}
    title="{{ $label }}" aria-label="{{ $label }}" @disabled(blank($value))>
    <svg x-show="!copied" class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
        aria-hidden="true">
        <path d="M8 8.75H6.5A2.25 2.25 0 0 0 4.25 11v6.5a2.25 2.25 0 0 0 2.25 2.25H13a2.25 2.25 0 0 0 2.25-2.25V16"
            stroke-width="1.5" stroke-linecap="round" />
        <rect x="8.75" y="4.25" width="11" height="11" rx="2.25" stroke-width="1.5" />
    </svg>
    <svg x-show="copied" x-cloak class="size-3.5 text-green-500" viewBox="0 0 24 24" fill="none"
        stroke="currentColor" aria-hidden="true">
        <path d="m6.75 12.25 3.5 3.5 7-7" stroke-width="1.5" stroke-linecap="round"
            stroke-linejoin="round" />
    </svg>
</button>
