@props([
    'label' => null,
    'status' => null,
    'type' => 'neutral',
    'as' => 'span',
])

@php
    $typeClasses = [
        'neutral' => 'border-neutral-200 bg-neutral-100 text-black dark:border-coolgray-300 dark:bg-coolgray-200 dark:text-white',
        'success' => 'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950/30 dark:text-green-300',
        'warning' => 'border-yellow-300 bg-yellow-50 text-yellow-900 dark:border-yellow-800 dark:bg-yellow-950/30 dark:text-yellow-200',
        'error' => 'border-red-300 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300',
    ];
@endphp

@if ($as === 'button')
    <button {{ $attributes->class([
        'inline-flex h-5 max-w-full items-center gap-1 rounded-sm border px-1.5 text-xs font-medium leading-4 transition-colors',
        $typeClasses[$type] ?? $typeClasses['neutral'],
    ])->merge(['type' => 'button']) }}>
        {{ collect([$label, $status])->filter()->join(' ') }}
    </button>
@elseif ($as === 'a')
    <a {{ $attributes->class([
        'inline-flex h-5 max-w-full items-center gap-1 rounded-sm border px-1.5 text-xs font-medium leading-4 transition-colors',
        $typeClasses[$type] ?? $typeClasses['neutral'],
    ]) }}>
        {{ collect([$label, $status])->filter()->join(' ') }}
    </a>
@else
    <span {{ $attributes->class([
        'inline-flex h-5 max-w-full items-center gap-1 rounded-sm border px-1.5 text-xs font-medium leading-4',
        $typeClasses[$type] ?? $typeClasses['neutral'],
    ]) }}>
        {{ collect([$label, $status])->filter()->join(' ') }}
    </span>
@endif
