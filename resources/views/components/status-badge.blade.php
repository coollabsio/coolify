@props([
    'label' => null,
    'status' => null,
    'type' => 'neutral',
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
    ];

    $sizeClasses = [
        'sm' => 'h-4 gap-1 px-1.5 text-[10px]',
        'md' => 'h-6 gap-1.5 px-2 text-xs',
    ];

    $baseClasses = 'inline-flex max-w-full items-center whitespace-nowrap rounded-full border border-neutral-200 bg-neutral-100 font-medium leading-none text-neutral-700 dark:border-white/[0.12] dark:bg-white/[0.07] dark:text-white ' . ($sizeClasses[$size] ?? $sizeClasses['md']);
@endphp

@if ($as === 'button')
    <button {{ $attributes->class([$baseClasses, 'transition-colors'])->merge(['type' => 'button']) }}>
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
    <a {{ $attributes->class([$baseClasses, 'transition-colors']) }}>
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
