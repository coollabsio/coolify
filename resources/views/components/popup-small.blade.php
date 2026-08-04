@props(['title' => 'Default title', 'description' => 'Default description'])

<div x-data="{ bannerVisible: true }" x-show="bannerVisible" x-cloak
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="translate-y-3 opacity-0"
    x-transition:enter-end="translate-y-0 opacity-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="translate-y-0 opacity-100"
    x-transition:leave-end="translate-y-3 opacity-0"
    class="fixed bottom-4 right-4 z-999 w-[calc(100%-2rem)] max-w-md">
    <div class="relative flex items-start gap-3 rounded-lg p-4 pr-12"
        style="background: var(--coollabs-elevated); box-shadow: 0 0 0 1px var(--coollabs-line), var(--shadow-modal);">
        @isset($icon)
            <div
                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 dark:bg-warning/10 dark:text-warning">
                {{ $icon }}
            </div>
        @endisset

        <div class="min-w-0 flex-1 pt-0.5">
            <h4 class="text-sm font-semibold leading-5 text-neutral-950 dark:text-fg">
                {{ $title }}
            </h4>
            <div class="mt-1 text-xs leading-5 text-neutral-600 dark:text-fg-dim">
                {{ $description }}
            </div>
        </div>

        <button type="button" @click="bannerVisible = false" aria-label="Dismiss"
            class="absolute right-3 top-3 flex size-7 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-black/5 hover:text-neutral-700 dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
            <x-reicon name="x" class="size-3.5" />
        </button>
    </div>
</div>
