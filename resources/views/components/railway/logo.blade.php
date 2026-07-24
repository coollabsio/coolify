@props(['size' => 'w-6 h-6'])

{{-- Neutral circular brand mark for the Railway-style shell. --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center justify-center']) }}>
    <svg class="{{ $size }}" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle cx="12" cy="12" r="11" fill="#0b0b0f" stroke="#ffffff" stroke-width="1.4"/>
        <path d="M4 14.5c4-1.6 12-1.6 16 0M6 10c3-1.1 9-1.1 12 0" stroke="#ffffff" stroke-width="1.4" stroke-linecap="round"/>
    </svg>
</span>
