<div x-data="{
    dropdownOpen: false
}" class="relative" @click.outside="dropdownOpen = false">
    <button @click="dropdownOpen=true"
        class="inline-flex items-center justify-start pr-8 transition-colors focus:outline-none disabled:opacity-50 disabled:pointer-events-none">
        <span class="flex flex-col items-start h-full leading-none">
            {{ $title }}
        </span>
        <svg class="absolute right-0 w-4 h-4 mr-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
            stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
        </svg>
    </button>

    <div x-show="dropdownOpen" @click.away="dropdownOpen=false" x-transition:enter="ease-out duration-200"
        x-transition:enter-start="-translate-y-2" x-transition:enter-end="translate-y-0"
        class="absolute top-0 z-50 mt-6 min-w-max" x-cloak>
        <div class="p-1 mt-1 bg-white border rounded shadow dark:bg-coolgray-200 dark:border-black border-neutral-300">
            {{ $slot }}
        </div>
    </div>
</div>
