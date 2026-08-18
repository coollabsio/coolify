@props(['canRestart' => false])

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" aria-label="Proxy configuration changes not applied" aria-haspopup="dialog"
        :aria-expanded="open" @click="open = !open"
        class="flex h-8 items-center justify-center gap-1.5 rounded-lg px-2 text-amber-700 transition-colors hover:bg-amber-100 dark:text-warning dark:hover:bg-warning/10">
        <x-reicon name="alert-triangle" class="size-4" />
        <span class="hidden text-xs font-medium lg:inline">Changes pending</span>
    </button>

    <div x-show="open" x-cloak x-transition.opacity role="dialog"
        class="fixed top-14 left-1/2 z-[1100] w-[calc(100vw-2rem)] max-w-sm -translate-x-1/2 rounded-lg p-3 lg:absolute lg:top-full lg:right-0 lg:left-auto lg:mt-2 lg:translate-x-0"
        style="background: var(--coollabs-elevated); box-shadow: 0 0 0 1px var(--coollabs-line), var(--shadow-modal);">
        <div class="flex items-start gap-2.5">
            <span
                class="flex size-7 shrink-0 items-center justify-center rounded-md bg-amber-100 text-amber-700 dark:bg-warning/10 dark:text-warning">
                <x-reicon name="alert-triangle" class="size-4" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-[13px] leading-4 font-semibold text-neutral-950 dark:text-fg">
                    The saved proxy configuration has not been applied
                </p>
                <p class="mt-0.5 text-[11px] leading-4 text-neutral-600 dark:text-fg-dim">
                    Restart the proxy to apply these changes.
                    @if ($canRestart)
                        <button type="button"
                            class="ml-0.5 inline-flex items-center gap-0.5 font-semibold text-coollabs transition-colors hover:text-coollabs-100 dark:text-warning dark:hover:text-warning/80"
                            @click="open = false; document.getElementById('server-mobile-restart-proxy-trigger')?.click()">
                            Restart proxy
                            <x-reicon name="arrow-right" class="size-2.5" />
                        </button>
                    @endif
                </p>
            </div>
        </div>
    </div>
</div>
