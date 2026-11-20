@props([
    'type' => 'info',
])

@php
    $styles = match ($type) {
        'success' => 'border-success/35 bg-success/10 text-success',
        'error' => 'border-error/35 bg-error/10 text-error',
        'warning' => 'border-warning/35 bg-warning/10 text-warning',
        default => 'border-neutral-300 bg-neutral-100 text-neutral-700 dark:border-white/10 dark:bg-white/[0.04] dark:text-fg-dim',
    };

    $icon = match ($type) {
        'success' => 'check-circle',
        'error' => 'alert-circle',
        'warning' => 'alert-triangle',
        default => 'info-circle',
    };
@endphp

<div {{ $attributes->merge(['class' => "flex items-start gap-3 rounded-lg border px-3 py-2.5 text-sm {$styles}"]) }}>
    <x-reicon :name="$icon" class="mt-0.5 size-4 shrink-0" />
    <div class="min-w-0 flex-1 leading-5">
        {{ $slot }}
    </div>
</div>
