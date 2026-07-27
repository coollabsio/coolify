@props([
    'conflicts' => [],
    'showModal' => false,
    'confirmAction' => 'confirmDomainUsage',
])

@if ($showModal && count($conflicts) > 0)
    <div x-data="{ modalOpen: true }" x-init="$nextTick(() => { modalOpen = true })"
        @keydown.escape.window="modalOpen = false; $wire.set('showDomainConflictModal', false)"
        :class="{ 'z-40': modalOpen }" class="relative w-auto h-auto">
        <template x-teleport="body">
            <div x-show="modalOpen"
                class="fixed inset-0 z-99 flex min-h-full items-center justify-center overflow-y-auto p-4" x-cloak>
                <div x-show="modalOpen" class="absolute inset-0 bg-black/50 backdrop-blur-[2px]"></div>
                <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                    class="application-settings-form application-settings-section relative w-full lg:min-w-[36rem] lg:max-w-2xl"
                    style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                    <header>
                        <h3>Domain already in use</h3>
                        <button @click="modalOpen = false; $wire.set('showDomainConflictModal', false)"
                            class="flex size-7 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                            <x-reicon name="x" class="size-4" />
                        </button>
                    </header>
                    <div class="application-settings-section-body">
                        <x-callout type="danger" title="Domain conflict detected" class="mb-4">
                            The following domain(s) are already in use by other resources. Using the same domain for
                            multiple resources can cause routing conflicts and unpredictable behavior.
                        </x-callout>

                        <div class="mb-4">
                            <ul class="space-y-2">
                                @foreach ($conflicts as $conflict)
                                    <li class="flex items-start text-[12px] leading-5 text-red-600 dark:text-red-400">
                                        <div>
                                            <strong>{{ $conflict['domain'] }}</strong> is used by
                                            @if ($conflict['resource_type'] === 'instance')
                                                <strong>{{ $conflict['resource_name'] }}</strong>
                                            @else
                                                <a href="{{ $conflict['resource_link'] }}" target="_blank"
                                                    class="underline hover:text-red-400">
                                                    {{ $conflict['resource_name'] }}
                                                </a>
                                            @endif
                                            ({{ $conflict['resource_type'] }})
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        <x-callout type="warning" title="What will happen if you continue?" class="mb-4">
                            @if (isset($consequences))
                                {{ $consequences }}
                            @else
                                <ul class="mt-2 ml-4 list-disc">
                                    <li>Only one resource will be accessible at this domain</li>
                                    <li>The routing behavior will be unpredictable</li>
                                    <li>You may experience service disruptions</li>
                                    <li>SSL certificates might not work correctly</li>
                                </ul>
                            @endif
                        </x-callout>

                        <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-4 dark:border-border-subtle">
                            <x-forms.button @click="modalOpen = false; $wire.set('showDomainConflictModal', false)">
                                Cancel
                            </x-forms.button>
                            <x-forms.button wire:click="{{ $confirmAction }}" @click="modalOpen = false" isError>
                                Proceed anyway
                            </x-forms.button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
@endif
