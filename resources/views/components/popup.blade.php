@props(['title' => 'Default title', 'description' => 'Default Description', 'buttonText' => 'Default Button Text'])
<div x-data="{
    bannerVisible: false,
    bannerVisibleAfter: 300
}" x-show="bannerVisible" x-transition:enter="transition ease-out duration-500"
    x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
    x-transition:leave="transition ease-in duration-300" x-transition:leave-start="translate-y-0"
    x-transition:leave-end="translate-y-full" x-init="setTimeout(() => { bannerVisible = true }, bannerVisibleAfter);"
    class="fixed bottom-0 right-0 w-full h-auto duration-300 ease-out sm:px-5 sm:pb-5 sm:w-[26rem] lg:w-full z-[999]"
    x-cloak>
    <div
        class="flex flex-col items-center justify-between w-full h-full max-w-4xl p-6 mx-auto bg-white border shadow-lg lg:border-t dark:border-coolgray-300 dark:bg-coolgray-100 lg:p-8 lg:flex-row sm:rounded">
        <div
            class="flex flex-col items-start h-full pb-6 text-xs lg:items-center lg:flex-row lg:pb-0 lg:pr-6 lg:space-x-5 dark:text-neutral-300">
            @if (isset($icon))
                {{ $icon }}
            @endif

            <div class="pt-6 lg:pt-0">
                <h4 class="w-full mb-1 text-xl font-bold leading-none -translate-y-1 text-neutral-900 dark:text-white">
                    {{ $title }}
                </h4>
                <p class="">{{ $description }}</span></p>
            </div>
        </div>
        <div class="flex items-end justify-end w-full pl-3 space-x-3 lg:flex-shrink-0 lg:w-auto">
            <button
                @if ($buttonText->attributes->whereStartsWith('@click')->first()) @click="bannerVisible=false;{{ $buttonText->attributes->get('@click') }}"
                @else
                @click="bannerVisible=false;" @endif
                class="inline-flex items-center justify-center flex-shrink-0 w-1/2 px-4 py-2 text-sm font-medium tracking-wide transition-colors duration-200 rounded-md bg-neutral-100 hover:bg-neutral-200 dark:bg-coolgray-200 lg:w-auto dark:text-neutral-200 dark:hover:bg-coolgray-300 focus:shadow-outline focus:outline-none">
                {{ $buttonText }}
            </button>
        </div>
    </div>
</div>
