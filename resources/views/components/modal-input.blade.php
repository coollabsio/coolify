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
    'wireIgnore' => true,
])

@php
    $modalId = 'modal-' . uniqid();
@endphp

<div x-data="{ modalOpen: false }"
    x-init="$watch('modalOpen', value => { if (!value) { $wire.dispatch('modalClosed') } })"
    :class="{ 'z-40': modalOpen }" @keydown.window.escape="modalOpen=false"
    class="relative w-auto h-auto" @close-modal.window="modalOpen=false" @if ($wireIgnore) wire:ignore @endif>
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
                class="absolute inset-0 w-full h-full bg-black/50 backdrop-blur-[2px]"></div>
            <div @if ($closeOutside) @click.self="modalOpen=false" @endif class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                <div id="{{ $modalId }}" x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                    x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                    class="application-settings-form application-settings-section relative max-h-[calc(100dvh-2rem)] w-full lg:w-auto lg:min-w-2xl lg:max-w-4xl"
                    style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                    <header>
                        <h3>{{ $title }}</h3>
                        <button @click="modalOpen=false"
                            class="cursor-pointer flex items-center justify-center w-7 h-7 rounded-md text-neutral-500 dark:text-fg-faint hover:bg-neutral-100 dark:hover:bg-white/[0.06] hover:text-black dark:hover:text-fg outline-0 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent">
                            <x-reicon name="x" class="size-4" />
                        </button>
                    </header>
                    <div class="application-settings-section-body min-h-0 flex-1 overflow-y-auto"
                        style="-webkit-overflow-scrolling: touch;">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
