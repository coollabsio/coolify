@props(['closeWithX' => false, 'fullScreen' => false])
<div x-data="{
    slideOverOpen: false
}" class="relative w-auto h-auto" {{ $attributes }}
   >
    {{ $slot }}
    <template x-teleport="body">
        <div x-show="slideOverOpen" @if (!$closeWithX) @keydown.window.escape="slideOverOpen=false" @endif
            class="relative z-[99] ">
            <div x-show="slideOverOpen" @if (!$closeWithX) @click="slideOverOpen = false" @endif
                class="fixed inset-0 dark:bg-black/60 backdrop-blur-sm"></div>
            <div class="fixed inset-0 overflow-hidden">
                <div class="absolute inset-0 overflow-hidden ">
                    <div class="fixed inset-y-0 right-0 flex max-w-full pl-10">
                        <div x-show="slideOverOpen"
                            @if (!$closeWithX) @click.away="slideOverOpen = false" @endif
                            x-transition:enter="transform transition ease-in-out duration-100 sm:duration-300"
                            x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                            x-transition:leave="transform transition ease-in-out duration-100 sm:duration-300"
                            x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
                            @class([
                                'max-w-xl w-screen' => !$fullScreen,
                                'max-w-4xl w-screen' => $fullScreen,
                            ])>
                            <div
                                class="flex flex-col h-full py-6 overflow-hidden border-l shadow-lg bg-neutral-50 dark:bg-base dark:border-neutral-800 border-neutral-200">
                                <div class="px-4 pb-4 sm:px-5">
                                    <div class="flex items-start justify-between pb-1">
                                        <h2 class="text-2xl leading-6" id="slide-over-title">
                                            {{ $title }}</h2>
                                        <div class="flex items-center h-auto ml-3">
                                            <button class="icon" @click="slideOverOpen=false"
                                                class="absolute top-0 right-0 z-30 flex items-center justify-center px-3 py-2 mt-4 mr-2 space-x-1 text-xs font-normal border-none rounded">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none"
                                                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="relative flex-1 px-4 mt-5 overflow-auto sm:px-5 scrollbar">
                                    <div class="absolute inset-0 px-4 sm:px-5">
                                        {{ $content }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
