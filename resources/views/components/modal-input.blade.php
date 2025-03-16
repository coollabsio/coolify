@props([
    'title' => 'Are you sure?',
    'buttonTitle' => 'Open Modal',
    'isErrorButton' => false,
    'isHighlightedButton' => false,
    'disabled' => false,
    'action' => 'delete',
    'content' => null,
    'closeOutside' => true,
    'minWidth' => '36rem',
])
<div x-data="{ modalOpen: false }" :class="{ 'z-40': modalOpen }" @keydown.window.escape="modalOpen=false"
    class="relative w-auto h-auto" wire:ignore>
    @if ($content)
        <div @click="modalOpen=true">
            {{ $content }}
        </div>
    @else
        @if ($disabled)
            <x-forms.button isError disabled>{{ $buttonTitle }}</x-forms.button>
        @elseif ($isErrorButton)
            <x-forms.button isError @click="modalOpen=true">{{ $buttonTitle }}</x-forms.button>
        @elseif ($isHighlightedButton)
            <x-forms.button isHighlighted @click="modalOpen=true">{{ $buttonTitle }}</x-forms.button>
        @else
            <x-forms.button @click="modalOpen=true">{{ $buttonTitle }}</x-forms.button>
        @endif
    @endif
    <template x-teleport="body">
        <div x-show="modalOpen"
            class="fixed top-0 left-0 lg:px-0 px-4 z-[99] flex items-center justify-center w-screen h-screen">
            <div x-show="modalOpen" x-transition:enter="ease-out duration-100" x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-100"
                x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                @if ($closeOutside) @click="modalOpen=false" @endif
                class="absolute inset-0 w-full h-full bg-black bg-opacity-20 backdrop-blur-sm"></div>
            <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                x-transition:enter="ease-out duration-100"
                x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                class="relative w-full py-6 border rounded drop-shadow min-w-full lg:min-w-[{{ $minWidth }}] max-w-fit bg-white border-neutral-200 dark:bg-base px-6 dark:border-coolgray-300">
                <div class="flex items-center justify-between pb-3">
                    <h3 class="text-2xl font-bold">{{ $title }}</h3>
                    <button @click="modalOpen=false"
                        class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300">
                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="relative flex items-center justify-center w-auto">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </template>
</div>
