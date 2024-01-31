@props([
    'title' => 'Are you sure?',
    'buttonTitle' => 'Open Modal',
    'isErrorButton' => false,
    'disabled' => false,
    'action' => 'delete',
])
<div x-data="{ modalOpen: false }" @keydown.escape.window="modalOpen = false" :class="{ 'z-40': modalOpen }"
    class="relative w-auto h-auto">
    @if ($disabled)
        <x-forms.button isError disabled>{{ $buttonTitle }}</x-forms.button>
    @elseif ($isErrorButton)
        <x-forms.button isError @click="modalOpen=true">{{ $buttonTitle }}</x-forms.button>
    @else
        <x-forms.button @click="modalOpen=true">{{ $buttonTitle }}</x-forms.button>
    @endif
    <template x-teleport="body">
        <div x-show="modalOpen" class="fixed top-0 left-0 z-[99] flex items-center justify-center w-screen h-screen"
            x-cloak>
            <div x-show="modalOpen" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-300"
                x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="modalOpen=false"
                class="absolute inset-0 w-full h-full bg-black backdrop-blur-sm bg-opacity-70"></div>
            <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                class="relative w-full py-6 border shadow-lg bg-coolgray-100 px-7 border-coolgray-300 sm:max-w-lg sm:rounded-lg">
                <div class="flex items-center justify-between pb-3">
                    <h3 class="text-lg font-semibold">{{ $title }}</h3>
                    <button @click="modalOpen=false"
                        class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 text-white rounded-full hover:bg-coolgray-300">
                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="relative w-auto pb-8">
                    {{ $slot }}
                </div>
                <div class="flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-2">
                    <x-forms.button @click="modalOpen=false" class="w-24 bg-coolgray-200 hover:bg-coolgray-300"
                        type="button">Cancel
                    </x-forms.button>
                    <div class="flex-1"></div>
                    <x-forms.button @click="modalOpen=false" class="w-24" isError type="button"
                        wire:click.prevent='{{ $action }}'>Continue
                    </x-forms.button>
                </div>
            </div>
        </div>
    </template>
</div>
