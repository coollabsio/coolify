@props([
    'label' => null,
    'status' => null,
    'type' => 'neutral',
    'as' => 'span',
    'dynamic' => false,
    'pulse' => false, // ping the dot for a state that is still in progress
])

@php
    $dotClasses = [
        'neutral' => 'bg-neutral-400 dark:bg-neutral-500',
        'success' => 'bg-emerald-500',
        'warning' => 'bg-warning',
        'error' => 'bg-red-500',
    ];

    $baseClasses = 'inline-flex h-6 max-w-full items-center gap-1.5 whitespace-nowrap rounded-full border border-neutral-200 bg-neutral-100 px-2 text-xs font-medium leading-none text-neutral-700 dark:border-white/[0.12] dark:bg-white/[0.07] dark:text-white';
@endphp

@if ($as === 'button')
    <button {{ $attributes->class([$baseClasses, 'transition-colors'])->merge(['type' => 'button']) }}>
        @if ($dynamic)
            {{ $slot }}
        @else
            <span class="relative flex size-1.5 shrink-0">
                @if ($pulse)
                    <span class="absolute inline-flex size-full rounded-full opacity-75 motion-safe:animate-ping {{ $dotClasses[$type] ?? $dotClasses['neutral'] }}"></span>
                @endif
                <span class="relative inline-flex size-1.5 rounded-full {{ $dotClasses[$type] ?? $dotClasses['neutral'] }}"></span>
            </span>
            <span class="truncate">{{ collect([$label, $status])->filter()->join(' ') }}</span>
        @endif
    </button>
@elseif ($as === 'a')
    <a {{ $attributes->class([$baseClasses, 'transition-colors']) }}>
        @if ($dynamic)
            {{ $slot }}
        @else
            <span class="relative flex size-1.5 shrink-0">
                @if ($pulse)
                    <span class="absolute inline-flex size-full rounded-full opacity-75 motion-safe:animate-ping {{ $dotClasses[$type] ?? $dotClasses['neutral'] }}"></span>
                @endif
                <span class="relative inline-flex size-1.5 rounded-full {{ $dotClasses[$type] ?? $dotClasses['neutral'] }}"></span>
            </span>
            <span class="truncate">{{ collect([$label, $status])->filter()->join(' ') }}</span>
        @endif
    </a>
@else
    <span {{ $attributes->class([$baseClasses]) }}>
        @if ($dynamic)
            {{ $slot }}
        @else
            <span class="relative flex size-1.5 shrink-0">
                @if ($pulse)
                    <span class="absolute inline-flex size-full rounded-full opacity-75 motion-safe:animate-ping {{ $dotClasses[$type] ?? $dotClasses['neutral'] }}"></span>
                @endif
                <span class="relative inline-flex size-1.5 rounded-full {{ $dotClasses[$type] ?? $dotClasses['neutral'] }}"></span>
            </span>
            <span class="truncate">{{ collect([$label, $status])->filter()->join(' ') }}</span>
        @endif
    </span>
@endif
