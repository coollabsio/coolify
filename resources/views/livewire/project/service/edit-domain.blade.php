<div class="w-full">
    <form wire:submit.prevent='submit' class="flex w-full flex-col gap-4">
        @if($requiredPort)
            <x-callout type="info" title="Required Port: {{ $requiredPort }}" class="mb-2">
                This service requires port <strong>{{ $requiredPort }}</strong> to function correctly. All domains must include this port number (or any other port if you know what you're doing).
                <br><br>
                <strong>Example:</strong> https://app.coolify.io:{{ $requiredPort }},https://www.app.coolify.io:{{ $requiredPort }}
            </x-callout>
        @endif

        <x-forms.input canGate="update" :canResource="$application" placeholder="https://app.coolify.io" label="Domains"
            id="fqdn"
            helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- https://app.coolify.io,https://cloud.coolify.io/dashboard<br>- https://app.coolify.io/api/v3<br>- https://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container.<br>- https://app.coolify.io:8080/api -> app.coolify.io/api will point to port 8080 inside the container."></x-forms.input>
        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-border-subtle">
            <x-forms.button canGate="update" :canResource="$application" type="submit">Save domain</x-forms.button>
        </div>
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
                            <h3>Remove required port?</h3>
                            <button @click="modalOpen = false; $wire.call('cancelRemovePort')"
                                class="flex size-7 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>
                        <div class="application-settings-section-body">
                            <x-callout type="warning" title="Port requirement" class="mb-4">
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

                            <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-4 dark:border-border-subtle">
                                <x-forms.button @click="modalOpen = false; $wire.call('cancelRemovePort')"
                                    class="w-auto">
                                    Keep port
                                </x-forms.button>
                                <x-forms.button wire:click="confirmRemovePort" @click="modalOpen = false" class="w-auto"
                                    isError>
                                    Remove port anyway
                                </x-forms.button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif
</div>
