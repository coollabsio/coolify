@props([
    'title' => 'Are you sure?',
    'isErrorButton' => false,
    'buttonTitle' => 'REWRITE THIS BUTTON TITLE PLEASSSSEEEE',
    'buttonFullWidth' => false,
    'customButton' => null,
    'disabled' => false,
    'action' => 'delete',
    'content' => null,
])
<div x-data="{ modalOpen: false }" @keydown.escape.window="modalOpen = false" :class="{ 'z-40': modalOpen }"
    class="relative w-auto h-auto">
    @if ($customButton)
        @if ($buttonFullWidth)
            <x-forms.button @click="modalOpen=true" class="w-full">
                {{ $customButton }}
            </x-forms.button>
        @else
            <x-forms.button @click="modalOpen=true">
                {{ $customButton }}
            </x-forms.button>
        @endif
    @else
        @if ($content)
            <div @click="modalOpen=true">
                {{ $content }}
            </div>
        @else
            @if ($disabled)
                @if ($buttonFullWidth)
                    <x-forms.button class="w-full" isError disabled wire:target>
                        {{ $buttonTitle }}
                    </x-forms.button>
                @else
                    <x-forms.button isError disabled wire:target>
                        {{ $buttonTitle }}
                    </x-forms.button>
                @endif
            @elseif ($isErrorButton)
                @if ($buttonFullWidth)
                    <x-forms.button class="w-full" isError @click="modalOpen=true">
                        {{ $buttonTitle }}
                    </x-forms.button>
                @else
                    <x-forms.button isError @click="modalOpen=true">
                        {{ $buttonTitle }}
                    </x-forms.button>
                @endif
            @else
                @if ($buttonFullWidth)
                    <x-forms.button @click="modalOpen=true" class="flex w-full gap-2" wire:target>
                        {{ $buttonTitle }}
                    </x-forms.button>
                @else
                    <x-forms.button @click="modalOpen=true" class="flex gap-2" wire:target>
                        {{ $buttonTitle }}
                    </x-forms.button>
                @endif
            @endif
        @endif
    @endif
    <template x-teleport="body">
        <div x-show="modalOpen"
            class="fixed top-0 lg:pt-10 left-0 z-[99] flex items-start justify-center w-screen h-screen" x-cloak>
            <div x-show="modalOpen" x-transition:enter="ease-out duration-100" x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-100"
                x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="modalOpen=false"
                class="absolute inset-0 w-full h-full bg-black bg-opacity-20 backdrop-blur-sm"></div>
            <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                class="relative w-full py-6 border rounded min-w-full lg:min-w-[36rem] max-w-fit bg-neutral-100 border-neutral-400 dark:bg-base px-7 dark:border-coolgray-300">
                <div class="flex items-center justify-between pb-3">
                    <h3 class="text-2xl font-bold">{{ $title }}</h3>
                    {{-- <button @click="modalOpen=false"
                        class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-coolgray-300">
                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button> --}}
                </div>
                <div class="relative w-auto pb-8">
                    {{ $slot }}
                </div>
                <div class="flex flex-row justify-end space-x-2">
                    <x-forms.button @click="modalOpen=false"
                        class="w-24 dark:bg-coolgray-200 dark:hover:bg-coolgray-300">Cancel
                    </x-forms.button>
                    <div class="flex-1"></div>
                    @if ($attributes->whereStartsWith('wire:click')->first())
                        @if ($isErrorButton)
                            <x-forms.button @click="modalOpen=false" class="w-24" isError type="button"
                                wire:click.prevent="{{ $attributes->get('wire:click') }}">Continue
                            </x-forms.button>
                        @else
                            <x-forms.button @click="modalOpen=false" class="w-24" isHighlighted type="button"
                                wire:click.prevent="{{ $attributes->get('wire:click') }}">Continue
                            </x-forms.button>
                        @endif
                    @elseif ($attributes->whereStartsWith('@click')->first())
                        @if ($isErrorButton)
                            <x-forms.button class="w-24" isError type="button"
                                @click="modalOpen=false;{{ $attributes->get('@click') }}">Continue
                            </x-forms.button>
                        @else
                            <x-forms.button class="w-24" isHighlighted type="button"
                                @click="modalOpen=false;{{ $attributes->get('@click') }}">Continue
                            </x-forms.button>
                        @endif
                    @elseif ($action)
                        @if ($isErrorButton)
                            <x-forms.button @click="modalOpen=false" class="w-24" isError type="button"
                                wire:click.prevent="{{ $action }}">Continue
                            </x-forms.button>
                        @else
                            <x-forms.button @click="modalOpen=false" class="w-24" isHighlighted type="button"
                                wire:click.prevent="{{ $action }}">Continue
                            </x-forms.button>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </template>
</div>
