<div>
    @if ($this->enabled)
        {{-- Floating chat widget (Intercom/Crisp style): a persistent launcher bubble in the
             bottom-right corner that pops a chat card. The open state survives full reloads
             (localStorage) and in-app navigation (@persist in the layout); the conversation
             itself resumes server-side until it has been idle for an hour. --}}
        <div x-data="{
                open: false,
                init() {
                    this.open = localStorage.getItem('coolify.assistant.open') === '1';
                    this.$watch('open', value => localStorage.setItem('coolify.assistant.open', value ? '1' : '0'));
                    if (this.open) { this.$nextTick(() => $wire.openThread()); }
                },
            }"
            x-on:open-assistant.window="open = true; $wire.openThread()"
            x-on:keydown.escape.window="open = false"
            class="fixed bottom-5 right-5 z-99 flex flex-col items-end gap-3 print:hidden">

            {{-- Chat card --}}
            <div x-show="open" x-cloak x-transition:enter="transition ease-[cubic-bezier(0.16,1,0.3,1)] duration-200"
                x-transition:enter-start="opacity-0 translate-y-4 scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                x-transition:leave="transition ease-[cubic-bezier(0.16,1,0.3,1)] duration-150"
                x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                x-transition:leave-end="opacity-0 translate-y-4 scale-95"
                class="surface-popover flex h-[min(640px,calc(100vh-8rem))] w-[min(400px,calc(100vw-2.5rem))] origin-bottom-right flex-col overflow-hidden rounded-2xl">
                <div class="flex items-center justify-between gap-2 border-b border-black/5 px-4 py-3 dark:border-white/5">
                    <div class="flex items-center gap-2">
                        <span class="flex size-7 items-center justify-center rounded-lg bg-coollabs text-white">
                            <x-reicon name="feedback" class="size-4" />
                        </span>
                        <h2 class="text-sm font-medium">Assistant</h2>
                    </div>
                    <div class="flex items-center gap-1">
                        <button type="button" wire:click="newThread" title="New chat"
                            class="flex items-center gap-1 rounded-md px-2 py-1 text-xs text-fg-faint transition-[transform,background-color,color] duration-100 ease-out hover:bg-neutral-100 hover:text-black active:scale-95 dark:hover:bg-white/[0.06] dark:hover:text-fg">
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
                            </svg>
                            New chat
                        </button>
                        <button type="button" @click="open = false" title="Close"
                            class="flex size-7 items-center justify-center rounded-md text-neutral-500 transition-[transform,background-color,color] duration-100 ease-out hover:bg-neutral-100 hover:text-black active:scale-95 dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="min-h-0 flex-1 overflow-hidden px-3 pb-3">
                    @if ($activeConversationId)
                        <livewire:ai.thread :conversation-id="$activeConversationId"
                            :key="'widget-thread-'.$activeConversationId" />
                    @endif
                </div>
            </div>

            {{-- Launcher bubble --}}
            <button type="button" @click="open = !open; if (open) $wire.openThread()"
                :title="open ? 'Close assistant' : 'Open assistant'"
                class="flex size-14 items-center justify-center rounded-full bg-coollabs text-white shadow-dropdown transition-transform duration-150 ease-out hover:scale-105 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs focus-visible:ring-offset-2 dark:focus-visible:ring-offset-base">
                <x-reicon name="feedback" class="size-6" x-show="!open" />
                <svg x-show="open" x-cloak xmlns="http://www.w3.org/2000/svg" class="size-6" fill="none"
                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    @endif
</div>
