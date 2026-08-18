@props([
    'type' => 'warning',
    'title' => 'Warning',
    'class' => '',
    'dismissible' => false,
    'onDismiss' => null,
])

@php
    $styles = [
        'warning' => [
            'icon' => 'alert-triangle',
            'shell' => 'border-warning/25 bg-warning/[0.07] dark:border-warning/20 dark:bg-warning/[0.06]',
            'iconClass' => 'text-warning-700 dark:text-warning',
            'titleClass' => 'text-warning-900 dark:text-warning',
            'textClass' => 'text-warning-900/75 dark:text-warning/70',
        ],
        'danger' => [
            'icon' => 'alert-circle',
            'shell' => 'border-red-300/60 bg-red-50 dark:border-red-500/20 dark:bg-red-500/[0.07]',
            'iconClass' => 'text-red-600 dark:text-red-400',
            'titleClass' => 'text-red-800 dark:text-red-300',
            'textClass' => 'text-red-700/80 dark:text-red-300/75',
        ],
        'info' => [
            'icon' => 'info-circle',
            'shell' => 'border-coollabs/20 bg-coollabs/[0.055] dark:border-warning/15 dark:bg-warning/[0.045]',
            'iconClass' => 'text-coollabs dark:text-warning',
            'titleClass' => 'text-coollabs dark:text-warning',
            'textClass' => 'text-neutral-600 dark:text-fg-dim',
        ],
        'success' => [
            'icon' => 'check-circle',
            'shell' => 'border-emerald-300/60 bg-emerald-50 dark:border-emerald-500/20 dark:bg-emerald-500/[0.07]',
            'iconClass' => 'text-emerald-600 dark:text-emerald-400',
            'titleClass' => 'text-emerald-800 dark:text-emerald-300',
            'textClass' => 'text-emerald-700/80 dark:text-emerald-300/75',
        ],
    ];

    $style = $styles[$type] ?? $styles['warning'];
@endphp

<div
    {{ $attributes->merge(['class' => 'relative rounded-lg border px-3 py-2.5 ' . $style['shell'] . ' ' . $class]) }}>
    <div class="flex items-start gap-2.5">
        <x-reicon :name="$style['icon']" class="mt-0.5 size-4 shrink-0 {{ $style['iconClass'] }}" />
        <div class="min-w-0 flex-1 {{ $dismissible ? 'pr-7' : '' }}">
            <div class="text-[12px] font-semibold {{ $style['titleClass'] }}">{{ $title }}</div>
            <div class="mt-0.5 text-[12px] leading-5 {{ $style['textClass'] }}">{{ $slot }}</div>
        </div>
        @if ($dismissible && $onDismiss)
            <button type="button" @click.stop="{{ $onDismiss }}"
                class="absolute top-1.5 right-1.5 flex size-7 items-center justify-center rounded-md transition-colors hover:bg-black/[0.05] dark:hover:bg-white/[0.06]"
                aria-label="Dismiss">
                <x-reicon name="x" class="size-3.5 {{ $style['iconClass'] }}" />
            </button>
        @endif
    </div>
</div>
