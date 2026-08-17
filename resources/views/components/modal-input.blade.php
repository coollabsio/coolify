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
    // Optional Livewire bool property to entangle open state (survives Livewire re-renders).
    'wireOpen' => null,
    'contentClicks' => true,
    'isLarge' => false,
])

@php
    $modalId = 'modal-' . uniqid();
@endphp

<div x-data="{ modalOpen: @if ($wireOpen) $wire.entangle(@js($wireOpen)) @else false @endif }"
    x-init="$watch('modalOpen', value => { if (!value) { $wire.dispatch('modalClosed') } })"
    :class="{ 'z-40': modalOpen }" @keydown.window.escape="modalOpen=false"
    {{ $attributes->class(['relative', $isFullWidth ? 'h-full w-full' : 'h-auto w-auto']) }}
    @close-modal.window="modalOpen=false" @if ($wireIgnore) wire:ignore @endif>
    @if ($content)
        <div @if ($contentClicks) @click="modalOpen=true" @endif @class(['h-full w-full' => $isFullWidth])>
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
                    @class([
                        'application-settings-form application-settings-section relative flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden',
                        'lg:w-[95vw]! lg:max-w-7xl!' => $isLarge,
                        'lg:w-auto lg:min-w-2xl lg:max-w-4xl' => ! $isLarge,
                    ])
                    style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                    <header class="flex-nowrap!">
                        <h3 class="min-w-0 flex-1 truncate">{{ $title }}</h3>
                        @isset($headerActions)
                            <div class="flex shrink-0 items-center gap-2">
                                {{ $headerActions }}
                            </div>
                        @endisset
                        <button type="button" @click="modalOpen=false"
                            class="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-neutral-500 outline-0 transition-colors hover:bg-neutral-100 hover:text-black focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
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
