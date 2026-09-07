@props(['diff' => []])

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" aria-label="Configuration changes not applied" aria-haspopup="dialog"
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
                <p class="text-[13px] font-semibold leading-4 text-neutral-950 dark:text-fg">
                    The latest configuration has not been applied
                </p>
                <p class="mt-0.5 text-[11px] leading-4 text-neutral-600 dark:text-fg-dim">
                    @if (data_get($diff, 'count'))
                        {{ data_get($diff, 'count') }}
                        {{ data_get($diff, 'count') === 1 ? 'change' : 'changes' }} unapplied.
                        {{ data_get($diff, 'requires_build') ? 'Rebuild required.' : 'Redeploy to apply.' }}
                        <button type="button"
                            class="ml-0.5 inline-flex items-center gap-0.5 font-semibold text-coollabs transition-colors hover:text-coollabs-100 dark:text-warning dark:hover:text-warning/80"
                            @click="open = false; $dispatch('open-configuration-diff')">
                            View changes
                            <x-reicon name="arrow-right" class="size-2.5" />
                        </button>
                    @else
                        Redeploy to apply.
                    @endif
                </p>
            </div>
        </div>
    </div>
</div>
