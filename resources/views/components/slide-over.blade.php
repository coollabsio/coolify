<div x-data="{
    slideOverOpen: false
}" class="relative w-auto h-auto">
    {{ $slot }}
    <template x-teleport="body">
        <div x-show="slideOverOpen" @keydown.window.escape="slideOverOpen=false" class="relative z-[99]">
            <div x-show="slideOverOpen" @click="slideOverOpen = false" class="fixed inset-0 "></div>
            <div class="fixed inset-0 overflow-hidden">
                <div class="absolute inset-0 overflow-hidden">
                    <div class="fixed inset-y-0 right-0 flex max-w-full pl-10">
                        <div x-show="slideOverOpen" @click.away="slideOverOpen = false"
                            x-transition:enter="transform transition ease-in-out duration-100 sm:duration-300"
                            x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                            x-transition:leave="transform transition ease-in-out duration-100 sm:duration-300"
                            x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
                            class="w-screen max-w-md">
                            <div
                                class="flex flex-col h-full py-5 overflow-y-scroll border-l shadow-lg bg-primary border-neutral-800">
                                <div class="px-4 sm:px-5">
                                    <div class="flex items-start justify-between pb-1">
                                        <h2 class="text-base leading-6" id="slide-over-title">
                                            {{ $title }}</h2>
                                        <div class="flex items-center h-auto ml-3">
                                            <button @click="slideOverOpen=false"
                                                class="absolute top-0 right-0 z-30 flex items-center justify-center px-3 py-2 mt-3 mr-5 space-x-1 text-xs font-normal border-none rounded">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none"
                                                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                                                    class="w-3 h-3">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="relative flex-1 px-4 mt-5 sm:px-5">
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
