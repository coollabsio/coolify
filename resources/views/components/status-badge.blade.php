@props([
    'label' => null,
    'status' => null,
    'type' => 'neutral', // neutral|success|warning|error|severity-critical|severity-high|severity-medium|severity-low
    'as' => 'span',
    'dynamic' => false,
    'pulse' => false, // ping the dot for a state that is still in progress
    'size' => 'md', // sm fits inline next to 12px table text without growing the row
    'spinning' => false, // show the loading spinner instead of the dot, e.g. for a running deployment
])

@php
    $dotClasses = [
        'neutral' => 'bg-neutral-400 dark:bg-neutral-500',
        'success' => 'bg-emerald-500',
        'warning' => 'bg-warning',
        'error' => 'bg-red-500',
        'severity-critical' => 'bg-error',
        'severity-high' => 'bg-severity-high',
        'severity-medium' => 'bg-severity-medium',
        'severity-low' => 'bg-neutral-400 dark:bg-neutral-500',
    ];

    $sizeClasses = [
        'sm' => 'gap-1 text-[11px]',
        'md' => 'gap-1.5 text-[13px]',
    ];

    // Bare: a colored dot and text, no pill. Interactive badges draw their hover background outside the box, so the dot stays aligned with plain text.
    $baseClasses = 'inline-flex max-w-full items-center whitespace-nowrap text-neutral-700 dark:text-fg ' . ($sizeClasses[$size] ?? $sizeClasses['md']);
    $interactiveClasses = 'relative isolate before:absolute before:-inset-x-1.5 before:-inset-y-1 before:-z-10 before:rounded-md before:transition-colors hover:before:bg-neutral-100 dark:hover:before:bg-white/[0.07]';
@endphp

@if ($as === 'button')
    <button {{ $attributes->class([$baseClasses, $interactiveClasses])->merge(['type' => 'button']) }}>
        @if ($dynamic)
            {{ $slot }}
        @else
            @if ($spinning)
                <svg class="loading-indicator size-3 shrink-0 animate-spin text-neutral-500 dark:text-fg-dim" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-20" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" />
                    <path class="opacity-80" d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
            @else
            <span class="relative flex size-1.5 shrink-0">
                @if ($pulse)
                    <span class="absolute inline-flex size-full rounded-full opacity-75 motion-safe:animate-ping {{ $dotClasses[$type] ?? $dotClasses['neutral'] }}"></span>
                @endif
                <span class="relative inline-flex size-1.5 rounded-full {{ $dotClasses[$type] ?? $dotClasses['neutral'] }}"></span>
            </span>
            @endif
            <span class="truncate">{{ collect([$label, $status])->filter()->join(' ') }}</span>
        @endif
    </button>
@elseif ($as === 'a')
    <a {{ $attributes->class([$baseClasses, $interactiveClasses]) }}>
        @if ($dynamic)
            {{ $slot }}
        @else
            @if ($spinning)
                <svg class="loading-indicator size-3 shrink-0 animate-spin text-neutral-500 dark:text-fg-dim" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-20" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" />
                    <path class="opacity-80" d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
            @else
            <span class="relative flex size-1.5 shrink-0">
                @if ($pulse)
                    <span class="absolute inline-flex size-full rounded-full opacity-75 motion-safe:animate-ping {{ $dotClasses[$type] ?? $dotClasses['neutral'] }}"></span>
                @endif
                <span class="relative inline-flex size-1.5 rounded-full {{ $dotClasses[$type] ?? $dotClasses['neutral'] }}"></span>
            </span>
            @endif
            <span class="truncate">{{ collect([$label, $status])->filter()->join(' ') }}</span>
        @endif
    </a>
@else
    <span {{ $attributes->class([$baseClasses]) }}>
        @if ($dynamic)
            {{ $slot }}
        @else
            @if ($spinning)
                <svg class="loading-indicator size-3 shrink-0 animate-spin text-neutral-500 dark:text-fg-dim" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle class="opacity-20" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" />
                    <path class="opacity-80" d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
            @else
            <span class="relative flex size-1.5 shrink-0">
                @if ($pulse)
                    <span class="absolute inline-flex size-full rounded-full opacity-75 motion-safe:animate-ping {{ $dotClasses[$type] ?? $dotClasses['neutral'] }}"></span>
                @endif
                <span class="relative inline-flex size-1.5 rounded-full {{ $dotClasses[$type] ?? $dotClasses['neutral'] }}"></span>
            </span>
            @endif
            <span class="truncate">{{ collect([$label, $status])->filter()->join(' ') }}</span>
        @endif
    </span>
@endif
