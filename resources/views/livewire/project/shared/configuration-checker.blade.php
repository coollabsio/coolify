<div>
    @if ($isConfigurationChanged && !is_null($resource->config_hash) && !$resource->isExited())
        <div x-data="{ configurationDiffModalOpen: false, expandedRows: {} }">
            <x-popup-small>
                <x-slot:title>
                    The latest configuration has not been applied
                </x-slot:title>
                <x-slot:icon>
                    <svg class="hidden w-10 h-10 dark:text-warning lg:block" viewBox="0 0 256 256"
                        xmlns="http://www.w3.org/2000/svg">
                        <path fill="currentColor"
                            d="M240.26 186.1L152.81 34.23a28.74 28.74 0 0 0-49.62 0L15.74 186.1a27.45 27.45 0 0 0 0 27.71A28.31 28.31 0 0 0 40.55 228h174.9a28.31 28.31 0 0 0 24.79-14.19a27.45 27.45 0 0 0 .02-27.71m-20.8 15.7a4.46 4.46 0 0 1-4 2.2H40.55a4.46 4.46 0 0 1-4-2.2a3.56 3.56 0 0 1 0-3.73L124 46.2a4.77 4.77 0 0 1 8 0l87.44 151.87a3.56 3.56 0 0 1 .02 3.73M116 136v-32a12 12 0 0 1 24 0v32a12 12 0 0 1-24 0m28 40a16 16 0 1 1-16-16a16 16 0 0 1 16 16" />
                    </svg>
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
                            <button type="button" class="ml-1 font-semibold underline text-coollabs dark:text-warning"
                                x-on:click="$wire.refreshConfigurationChanges().then(() => configurationDiffModalOpen = true)"
                                wire:loading.attr="disabled" wire:target="refreshConfigurationChanges">
                                View changes
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
                            class="absolute inset-0 h-full w-full bg-black/20 backdrop-blur-xs"
                            @click="configurationDiffModalOpen = false"></div>
                        <div x-show="configurationDiffModalOpen" x-trap.inert.noscroll="configurationDiffModalOpen"
                            x-transition:enter="ease-out duration-100"
                            x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                            x-transition:leave="ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                            x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                            class="relative flex max-h-[85vh] w-full flex-col rounded-sm border border-neutral-200 bg-white shadow-lg dark:border-coolgray-300 dark:bg-base lg:max-w-4xl">
                            <div class="flex shrink-0 items-center justify-between border-b border-neutral-200 px-6 py-5 dark:border-coolgray-300">
                                <div>
                                    <h3 class="text-2xl font-bold text-black dark:text-white">Configuration changes</h3>
                                    <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                                        These changes are not applied to the latest deployment yet.
                                    </p>
                                </div>
                                <button type="button" @click="configurationDiffModalOpen = false"
                                    class="flex h-8 w-8 items-center justify-center rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300 outline-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-base">
                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none"
                                        viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                            <div class="overflow-y-auto p-6">
                                <x-deployment.configuration-diff :diff="$configurationDiff" />
                            </div>
                        </div>
                    </div>
                </template>
            @endif
        </div>
    @endif
</div>
