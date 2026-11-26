@props(['diff' => [], 'canRestart' => false])

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" aria-label="Configuration changes not applied" aria-haspopup="dialog"
        :aria-expanded="open" @click="open = !open"
        class="flex h-8 items-center justify-center gap-1.5 rounded-lg px-2 text-amber-700 transition-colors hover:bg-amber-100 dark:text-warning dark:hover:bg-warning/10">
        <x-reicon name="alert-triangle" class="size-4" />
        <span class="hidden text-xs font-medium lg:inline">Changes pending</span>
    </button>

    <div x-show="open" x-cloak x-transition.opacity role="dialog"
        class="surface-popover fixed top-14 left-1/2 z-[1100] w-[calc(100vw-2rem)] max-w-sm -translate-x-1/2 rounded-lg p-3 lg:absolute lg:top-full lg:right-0 lg:left-auto lg:mt-2 lg:translate-x-0">
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
                        {{ data_get($diff, 'requires_build') ? 'A rebuild is necessary.' : 'A restart applies them. No build is necessary.' }}
                    @else
                        Redeploy to apply.
                    @endif
                </p>
                @if (data_get($diff, 'requires_build') && filled(data_get($diff, 'build_reasons')))
                    @php($buildReasons = collect(data_get($diff, 'build_reasons')))
                    <div class="mt-1.5 text-[11px] leading-4 text-neutral-600 dark:text-fg-dim">
                        <span class="font-medium text-neutral-800 dark:text-fg">Why:</span>
                        {{ $buildReasons->take(3)->implode(', ') }}{{ $buildReasons->count() > 3 ? ' and ' . ($buildReasons->count() - 3) . ' more' : '' }}.
                        A restart cannot apply these changes, because they change the image.
                    </div>
                @endif
                @if (data_get($diff, 'count'))
                    <div class="mt-2 flex items-center gap-3 text-[11px]">
                        @if ($canRestart && !data_get($diff, 'requires_build'))
                            <button type="button"
                                class="inline-flex items-center gap-1 font-semibold text-coollabs transition-colors hover:text-coollabs-100 dark:text-brand-light dark:hover:text-brand-light/80"
                                @click="open = false; document.getElementById('application-mobile-restart-trigger')?.click()">
                                <x-reicon name="restart" class="size-3" />
                                Restart
                            </button>
                        @endif
                        <button type="button"
                            class="inline-flex items-center gap-0.5 font-semibold text-coollabs transition-colors hover:text-coollabs-100 dark:text-brand-light dark:hover:text-brand-light/80"
                            @click="open = false; $dispatch('open-configuration-diff')">
                            View changes
                            <x-reicon name="arrow-right" class="size-2.5" />
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
