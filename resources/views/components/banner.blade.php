@props(['closable' => true])
<div x-data="{
    bannerVisible: false,
    bannerVisibleAfter: 100,
}" x-show="bannerVisible" x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="-translate-y-10" x-transition:enter-end="translate-y-0"
    x-transition:leave="transition ease-in duration-100" x-transition:leave-start="translate-y-0"
    x-transition:leave-end="-translate-y-10" x-init="setTimeout(() => { bannerVisible = true }, bannerVisibleAfter);"
    class="relative z-999 w-full py-2 mx-auto duration-100 ease-out shadow-xs border-b border-neutral-200 bg-white text-neutral-700 dark:border-white/[0.06] dark:bg-coolgray-100 dark:text-neutral-200 sm:py-0 sm:h-14" x-cloak>
    <div class="flex items-center justify-between h-full px-3">
        {{ $slot }}
        @if ($closable)
            <button @click="bannerVisible=false"
                class="flex items-center shrink-0 translate-x-1 ease-out duration-150 justify-center w-6 h-6 p-1.5 rounded-full text-neutral-500 hover:bg-neutral-100 hover:text-black dark:text-neutral-400 dark:hover:bg-white/[0.07] dark:hover:text-white">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                    stroke="currentColor" class="w-full h-full">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        @endif
    </div>
</div>
