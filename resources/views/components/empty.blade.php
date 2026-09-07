@props([
    'title',
    'description' => null,
    'size' => 'base', // sm | base | lg
    'iconName' => null, // reicon name; use with icon-name="…"
])

@php
    $minHeight = match ($size) {
        'sm' => 'min-h-44',
        'lg' => 'min-h-96',
        default => 'min-h-80',
    };

    $iconBox = match ($size) {
        'sm' => 'mb-3 size-10',
        'lg' => 'mb-4 size-12',
        default => 'mb-4 size-11',
    };

    $iconSize = match ($size) {
        'sm' => 'size-4.5',
        'lg' => 'size-6',
        default => 'size-5',
    };

    $titleClass = match ($size) {
        'sm' => 'text-[14px] font-semibold text-black dark:text-fg',
        'lg' => 'text-base font-semibold text-black dark:text-fg',
        default => 'text-[15px] font-semibold text-black dark:text-fg',
    };

    $descriptionClass = match ($size) {
        'sm' => 'mt-1 max-w-sm text-[12px] leading-5 text-neutral-500 dark:text-fg-dim',
        default => 'mt-1 max-w-sm text-[13px] leading-5 text-neutral-500 dark:text-fg-dim',
    };

    $hasIconSlot = isset($icon) && $icon instanceof \Illuminate\View\ComponentSlot && ! $icon->isEmpty();
    $hasIconName = filled($iconName);
    $hasIcon = $hasIconSlot || $hasIconName;

    // Prefer contents; fall back to actions (legacy slot name used in several views).
    $footer = null;
    if (isset($contents) && $contents instanceof \Illuminate\View\ComponentSlot && ! $contents->isEmpty()) {
        $footer = $contents;
    } elseif (isset($actions) && $actions instanceof \Illuminate\View\ComponentSlot && ! $actions->isEmpty()) {
        $footer = $actions;
    }
@endphp

{{-- Empty state: dashed card, icon badge, title, description, optional actions. --}}
<div
    {{ $attributes->merge([
        'class' => "empty-state flex w-full flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 px-6 py-10 text-center dark:border-white/[0.1] {$minHeight}",
    ]) }}>
    @if ($hasIcon)
        <div
            class="{{ $iconBox }} flex items-center justify-center rounded-xl border border-neutral-200 bg-white text-neutral-400 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-faint">
            @if ($hasIconSlot)
                {{ $icon }}
            @else
                <x-reicon :name="$iconName" class="{{ $iconSize }}" />
            @endif
        </div>
    @endif

    <h2 class="{{ $titleClass }}">{{ $title }}</h2>

    @if ($description)
        <p class="{{ $descriptionClass }}">{{ $description }}</p>
    @endif

    @if ($footer)
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
            {{ $footer }}
        </div>
    @endif
</div>
