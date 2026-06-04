@props([
    'title' => 'Are you sure?',
    'buttonTitle' => 'Open Modal',
    'isErrorButton' => false,
    'isHighlightedButton' => false,
    'disabled' => false,
    'action' => 'delete',
    'content' => null,
    'closeOutside' => true,
    'isFullWidth' => false,
])

@php
    $modalId = 'modal-' . uniqid();
@endphp

<div x-data="{ modalOpen: false }"
    x-init="$watch('modalOpen', value => { if (!value) { $wire.dispatch('modalClosed') } })"
    :class="{ 'z-40': modalOpen }" @keydown.window.escape="modalOpen=false"
    class="relative w-auto h-auto" wire:ignore>
    @if ($content)
        <div @click="modalOpen=true">
            {{ $content }}
        </div>
    @else
        @if ($disabled)
            <x-forms.button isError disabled @class(['w-full' => $isFullWidth])>{{ $buttonTitle }}</x-forms.button>
        @elseif ($isErrorButton)
            <x-forms.button isError @click="modalOpen=true" @class(['w-full' => $isFullWidth])>{{ $buttonTitle }}</x-forms.button>
        @elseif ($isHighlightedButton)
            <x-forms.button isHighlighted @click="modalOpen=true" @class(['w-full' => $isFullWidth])>{{ $buttonTitle }}</x-forms.button>
        @else
            <x-forms.button @click="modalOpen=true" @class(['w-full' => $isFullWidth])>{{ $buttonTitle }}</x-forms.button>
        @endif
    @endif
    <template x-teleport="body">
        <div x-show="modalOpen"
            x-init="$watch('modalOpen', value => { if(value) { $nextTick(() => { const firstInput = $el.querySelector('input, textarea, select'); firstInput?.focus(); }) } })"
            class="fixed inset-0 z-99 overflow-y-auto">
            <div x-show="modalOpen" x-transition:enter="ease-out duration-100" x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-100"
                x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
            <div @if ($closeOutside) @click.self="modalOpen=false" @endif class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                <div id="{{ $modalId }}" x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                    x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                    class="relative flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden rounded-sm border border-neutral-200 bg-white drop-shadow-sm dark:border-coolgray-300 dark:bg-base lg:w-auto lg:min-w-2xl lg:max-w-4xl">
                    <div class="flex items-center justify-between py-6 px-6 shrink-0">
                        <h3 class="text-2xl font-bold">{{ $title }}</h3>
                        <button @click="modalOpen=false"
                            class="absolute cursor-pointer top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300 outline-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-base">
                            <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="relative min-h-0 flex-1 overflow-y-auto px-6 pb-6 pt-1"
                        style="-webkit-overflow-scrolling: touch;">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
