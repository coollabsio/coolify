@props([
    'initial' => '?',
    'url' => null,
    'name' => null,
])

{{-- The message sender's avatar (image if set, else initial), sized to match the
     assistant glyph so both sides of the chat read as a balanced iMessage-style
     exchange. --}}
@if (filled($url))
    <img src="{{ $url }}" alt="{{ $name }}"
        {{ $attributes->class('size-6 shrink-0 rounded-full object-cover') }}>
@else
    <span
        {{ $attributes->class('flex size-6 shrink-0 items-center justify-center rounded-full bg-neutral-200 text-[11px] font-semibold text-neutral-700 dark:bg-white/[0.1] dark:text-fg') }}>{{ $initial }}</span>
@endif
