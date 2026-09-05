@props([
    'subject' => 'storage',
    'canGate' => null,
    'canResource' => null,
])

<div x-data="{ modalOpen: false }" @open-storage-sharing-modal.window="modalOpen = true"
    @keydown.escape.window="if (modalOpen) { modalOpen = false; $wire.cancelShareStorage() }"
    :class="{ 'z-40': modalOpen }" class="relative h-auto w-auto">
        <template x-teleport="body">
            <div x-show="modalOpen" class="fixed inset-0 z-99 flex min-h-full items-center justify-center overflow-y-auto p-4"
                x-cloak>
                <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]"></div>
                <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition
                    class="application-settings-form application-settings-section relative w-full lg:min-w-[36rem] lg:max-w-2xl"
                    style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                    <header>
                        <h3>Share {{ $subject }} with preview deployments?</h3>
                        <button type="button" @click="modalOpen = false; $wire.cancelShareStorage()"
                            class="flex size-7 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                            <x-reicon name="x" class="size-4" />
                        </button>
                    </header>
                    <div class="application-settings-section-body">
                        <x-callout type="danger" title="Production data will be shared">
                            Production and preview deployments will use the same data. Changes from either deployment can affect the other.
                        </x-callout>
                        <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                            <x-forms.button type="button" :canGate="$canGate" :canResource="$canResource"
                                @click="modalOpen = false; $wire.cancelShareStorage()">
                                Keep isolated
                            </x-forms.button>
                            <x-forms.button type="button" isError :canGate="$canGate" :canResource="$canResource"
                                wire:click="confirmShareStorage"
                                @click="modalOpen = false">
                                Share {{ $subject }}
                            </x-forms.button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
