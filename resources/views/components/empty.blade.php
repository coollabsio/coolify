@props([
    'title',
    'description' => null,
    'size' => 'base', // sm | base | lg
])

@php
    $padding = match ($size) {
        'sm' => 'py-6',
        'lg' => 'py-16',
        default => 'py-10',
    };
@endphp

{{-- Empty state: dimmed icon, title, subtle description, optional actions. --}}
<div {{ $attributes->merge(['class' => "flex w-full flex-col items-center justify-center gap-1 px-6 text-center {$padding}"]) }}>
    @isset($icon)
        <div class="mb-2 text-neutral-300 dark:text-fg-faint">{{ $icon }}</div>
    @endisset
    <h4 class="text-sm font-semibold text-black dark:text-fg">{{ $title }}</h4>
    @if ($description)
        <p class="max-w-md text-sm leading-5 text-neutral-500 dark:text-fg-dim">{{ $description }}</p>
    @endif
    @isset($contents)
        <div class="mt-3 flex items-center justify-center gap-2">{{ $contents }}</div>
    @endisset
</div>
