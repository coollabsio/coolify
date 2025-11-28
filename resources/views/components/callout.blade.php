@props(['type' => 'warning', 'title' => 'Warning', 'class' => '', 'dismissible' => false, 'onDismiss' => null])

@php
    $icons = [
        'warning' => '<svg class="w-5 h-5 text-yellow-600 dark:text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>',

        'danger' => '<svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg>',

        'info' => '<svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>',

        'success' => '<svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>'
    ];

    $colors = [
        'warning' => [
            'bg' => 'bg-yellow-50 dark:bg-yellow-900/30',
            'border' => 'border-yellow-300 dark:border-yellow-800',
            'title' => 'text-yellow-800 dark:text-yellow-300',
            'text' => 'text-yellow-700 dark:text-yellow-200'
        ],
        'danger' => [
            'bg' => 'bg-red-50 dark:bg-red-900/30',
            'border' => 'border-red-300 dark:border-red-800',
            'title' => 'text-red-800 dark:text-red-300',
            'text' => 'text-red-700 dark:text-red-200'
        ],
        'info' => [
            'bg' => 'bg-blue-50 dark:bg-blue-900/30',
            'border' => 'border-blue-300 dark:border-blue-800',
            'title' => 'text-blue-800 dark:text-blue-300',
            'text' => 'text-blue-700 dark:text-blue-200'
        ],
        'success' => [
            'bg' => 'bg-green-50 dark:bg-green-900/30',
            'border' => 'border-green-300 dark:border-green-800',
            'title' => 'text-green-800 dark:text-green-300',
            'text' => 'text-green-700 dark:text-green-200'
        ]
    ];

    $colorScheme = $colors[$type] ?? $colors['warning'];
    $icon = $icons[$type] ?? $icons['warning'];
@endphp

<div {{ $attributes->merge(['class' => 'relative p-4 border rounded-lg ' . $colorScheme['bg'] . ' ' . $colorScheme['border'] . ' ' . $class]) }}>
    <div class="flex items-start">
        <div class="flex-shrink-0">
            {!! $icon !!}
        </div>
        <div class="ml-3 {{ $dismissible ? 'pr-8' : '' }}">
            <div class="text-base font-bold {{ $colorScheme['title'] }}">
                {{ $title }}
            </div>
            <div class="mt-2 text-sm {{ $colorScheme['text'] }}">
                {{ $slot }}
            </div>
        </div>
        @if($dismissible && $onDismiss)
            <button type="button" @click.stop="{{ $onDismiss }}"
                    class="absolute top-2 right-2 p-1 rounded hover:bg-black/10 dark:hover:bg-white/10 transition-colors"
                    aria-label="Dismiss">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                     stroke="currentColor" class="w-4 h-4 {{ $colorScheme['text'] }}">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        @endif
    </div>
</div>