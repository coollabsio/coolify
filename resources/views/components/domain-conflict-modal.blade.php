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
                class="fixed top-0 lg:pt-10 left-0 z-99 flex items-start justify-center w-screen h-screen" x-cloak>
                <div x-show="modalOpen" class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
                <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                    class="relative w-full py-6 border rounded-sm min-w-full lg:min-w-[36rem] max-w-[48rem] bg-neutral-100 border-neutral-400 dark:bg-base px-7 dark:border-coolgray-300">
                    <div class="flex justify-between items-center pb-3">
                        <h2 class="pr-8 font-bold">Domain Already In Use</h2>
                        <button @click="modalOpen = false; $wire.set('showDomainConflictModal', false)"
                            class="flex absolute top-2 right-2 justify-center items-center w-8 h-8 rounded-full dark:text-white hover:bg-coolgray-300">
                            <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="relative w-auto">
                        <x-callout type="danger" title="Domain Conflict Detected" class="mb-4">
                            The following domain(s) are already in use by other resources. Using the same domain for
                            multiple resources can cause routing conflicts and unpredictable behavior.
                        </x-callout>

                        <div class="mb-4">
                            <ul class="space-y-2">
                                @foreach ($conflicts as $conflict)
                                    <li class="flex items-start text-red-500">
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

                        <div class="flex flex-wrap gap-2 justify-between mt-4">
                            <x-forms.button @click="modalOpen = false; $wire.set('showDomainConflictModal', false)"
                                class="w-auto dark:bg-coolgray-200 dark:hover:bg-coolgray-300">
                                Cancel
                            </x-forms.button>
                            <x-forms.button wire:click="{{ $confirmAction }}" @click="modalOpen = false" class="w-auto"
                                isError>
                                I understand, proceed anyway
                            </x-forms.button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
@endif
