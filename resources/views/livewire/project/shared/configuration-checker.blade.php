<div>
    @if ($isConfigurationChanged && !is_null($resource->config_hash) && !$resource->isExited())
        <div x-data="{ configurationDiffModalOpen: false, expandedRows: {} }">
            <x-popup-small>
                <x-slot:title>
                    The latest configuration has not been applied
                </x-slot:title>
                <x-slot:icon>
                    <x-reicon name="alert-triangle" class="size-5" />
                </x-slot:icon>
                <x-slot:description>
                    <span>
                        @if (data_get($configurationDiff, 'count'))
                            {{ data_get($configurationDiff, 'count') }} unapplied configuration
                            {{ data_get($configurationDiff, 'count') === 1 ? 'change' : 'changes' }} detected.
                            @if (data_get($configurationDiff, 'requires_build'))
                                A rebuild is required.
                            @else
                                Please redeploy to apply the new configuration.
                            @endif
                            <button type="button"
                                class="ml-1 inline-flex items-center gap-1 font-semibold text-coollabs transition-colors hover:text-coollabs-100 dark:text-warning dark:hover:text-warning/80"
                                x-on:click="$wire.refreshConfigurationChanges().then(() => configurationDiffModalOpen = true)"
                                wire:loading.attr="disabled" wire:target="refreshConfigurationChanges">
                                View changes
                                <x-reicon name="arrow-right" class="size-3" />
                            </button>
                        @else
                            Please redeploy to apply the new configuration.
                        @endif
                    </span>
                </x-slot:description>
            </x-popup-small>

            @if (data_get($configurationDiff, 'count'))
                <template x-teleport="body">
                    <div x-show="configurationDiffModalOpen" x-cloak
                        class="fixed inset-0 z-99 flex h-screen w-screen items-center justify-center p-4"
                        @keydown.escape.window="configurationDiffModalOpen = false">
                        <div x-show="configurationDiffModalOpen" x-transition.opacity
                            class="absolute inset-0 h-full w-full bg-black/55 backdrop-blur-[3px]"
                            @click="configurationDiffModalOpen = false"></div>
                        <div x-show="configurationDiffModalOpen" x-trap.inert.noscroll="configurationDiffModalOpen"
                            x-transition:enter="ease-out duration-100"
                            x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                            x-transition:leave="ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                            x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                            class="application-settings-form application-settings-section relative max-h-[calc(100dvh-2rem)] w-full max-w-3xl overflow-hidden"
                            style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                            <header>
                                <div class="flex items-center gap-2">
                                    <x-reicon name="alert-triangle"
                                        class="size-4 text-amber-600 dark:text-warning" />
                                    <h3>Configuration changes</h3>
                                </div>
                                <button type="button" @click="configurationDiffModalOpen = false"
                                    class="flex size-7 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-black/5 hover:text-neutral-800 dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                                    <x-reicon name="x" class="size-3.5" />
                                </button>
                            </header>
                            <div
                                class="application-settings-section-body min-h-0 flex-1 overflow-y-auto">
                                <div
                                    class="mb-4 flex items-center justify-between gap-3 rounded-lg bg-amber-50 px-3 py-2.5 ring-1 ring-amber-200 dark:bg-warning/[0.07] dark:ring-warning/15">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <div
                                            class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 dark:bg-warning/10 dark:text-warning">
                                            <x-reicon name="alert-triangle" class="size-4" />
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-neutral-900 dark:text-fg">
                                                {{ data_get($configurationDiff, 'count') }} configuration
                                                {{ data_get($configurationDiff, 'count') === 1 ? 'change' : 'changes' }}
                                            </p>
                                            <p class="text-xs text-neutral-600 dark:text-fg-dim">
                                                Deploy again to apply the latest values.
                                            </p>
                                        </div>
                                    </div>
                                    <x-status-badge
                                        :status="data_get($configurationDiff, 'requires_build') ? 'Rebuild required' : 'Redeploy required'"
                                        type="warning" />
                                </div>
                                <x-deployment.configuration-diff :diff="$configurationDiff" />
                            </div>
                        </div>
                    </div>
                </template>
            @endif
        </div>
    @endif
</div>
