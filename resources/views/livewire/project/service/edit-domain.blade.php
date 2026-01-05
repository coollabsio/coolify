<div class="w-full">
    <form wire:submit.prevent='submit' class="flex flex-col w-full gap-2">
        @if($requiredPort)
            <x-callout type="warning" title="Required Port: {{ $requiredPort }}" class="mb-2">
                This service requires port <strong>{{ $requiredPort }}</strong> to function correctly. All domains must include this port number (or any other port if you know what you're doing).
                <br><br>
                <strong>Example:</strong> http://app.coolify.io:{{ $requiredPort }}
            </x-callout>
        @endif

        <x-forms.input canGate="update" :canResource="$application" placeholder="https://app.coolify.io" label="Domains"
            id="fqdn"
            helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- http://app.coolify.io,https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3<br>- http://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container. "></x-forms.input>
        <x-forms.button canGate="update" :canResource="$application" type="submit">Save</x-forms.button>
    </form>

    <x-domain-conflict-modal :conflicts="$domainConflicts" :showModal="$showDomainConflictModal" confirmAction="confirmDomainUsage">
        <x-slot:consequences>
            <ul class="mt-2 ml-4 list-disc">
                <li>Only one service will be accessible at this domain</li>
                <li>The routing behavior will be unpredictable</li>
                <li>You may experience service disruptions</li>
                <li>SSL certificates might not work correctly</li>
            </ul>
        </x-slot:consequences>
    </x-domain-conflict-modal>

    @if ($showPortWarningModal)
        <div x-data="{ modalOpen: true }" x-init="$nextTick(() => { modalOpen = true })"
            @keydown.escape.window="modalOpen = false; $wire.call('cancelRemovePort')"
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
                            <h2 class="pr-8 font-bold">Remove Required Port?</h2>
                            <button @click="modalOpen = false; $wire.call('cancelRemovePort')"
                                class="flex absolute top-2 right-2 justify-center items-center w-8 h-8 rounded-full dark:text-white hover:bg-coolgray-300">
                                <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <div class="relative w-auto">
                            <x-callout type="warning" title="Port Requirement Warning" class="mb-4">
                                This service requires port <strong>{{ $requiredPort }}</strong> to function correctly.
                                One or more of your domains are missing a port number.
                            </x-callout>

                            <x-callout type="danger" title="What will happen if you continue?" class="mb-4">
                                <ul class="mt-2 ml-4 list-disc">
                                    <li>The service may become unreachable</li>
                                    <li>The proxy may not be able to route traffic correctly</li>
                                    <li>Environment variables may not be generated properly</li>
                                    <li>The service may fail to start or function</li>
                                </ul>
                            </x-callout>

                            <div class="flex flex-wrap gap-2 justify-between mt-4">
                                <x-forms.button @click="modalOpen = false; $wire.call('cancelRemovePort')"
                                    class="w-auto dark:bg-coolgray-200 dark:hover:bg-coolgray-300">
                                    Cancel - Keep Port
                                </x-forms.button>
                                <x-forms.button wire:click="confirmRemovePort" @click="modalOpen = false" class="w-auto"
                                    isError>
                                    I understand, remove port anyway
                                </x-forms.button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif
</div>
