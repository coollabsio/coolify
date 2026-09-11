<div>
    @if ($this->enabled)
        {{-- Floating chat widget (Intercom/Crisp style): a persistent launcher bubble in the
             bottom-right corner that pops a chat card. The open state survives full reloads
             (localStorage) and in-app navigation (@persist in the layout); the conversation
             itself resumes server-side until it has been idle for an hour. --}}
        <div x-data="{
                open: false,
                onAssistantPage: false,
                init() {
                    this.onAssistantPage = window.location.pathname.startsWith('/assistant');
                    this.open = localStorage.getItem('coolify.assistant.open') === '1';
                    this.$watch('open', value => localStorage.setItem('coolify.assistant.open', value ? '1' : '0'));
                    if (this.open && ! this.onAssistantPage) { this.$nextTick(() => $wire.openThread()); }
                },
            }"
            x-on:livewire:navigated.window="onAssistantPage = window.location.pathname.startsWith('/assistant'); if (onAssistantPage) open = false;"
            x-on:open-assistant.window="open = true; $wire.openThread()"
            x-on:keydown.escape.window="open = false"
            x-show="!onAssistantPage" x-cloak
            class="fixed bottom-5 right-5 z-99 flex flex-col items-end gap-3 print:hidden">

            {{-- Chat card --}}
            <div x-show="open" x-cloak
                x-transition:enter="transition ease-[cubic-bezier(0.16,1,0.3,1)] duration-200 motion-reduce:transition-none"
                x-transition:enter-start="opacity-0 translate-y-4 scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                x-transition:leave="transition ease-[cubic-bezier(0.16,1,0.3,1)] duration-150 motion-reduce:transition-none"
                x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                x-transition:leave-end="opacity-0 translate-y-4 scale-95"
                class="surface-popover flex h-[min(640px,calc(100vh-8rem))] w-[min(400px,calc(100vw-2.5rem))] origin-bottom-right flex-col overflow-hidden rounded-2xl">
                <x-ai.conversation-header :title="$this->active['title'] ?? 'Assistant'"
                    :subtitle="$this->active ? null : 'Online'" :online="!$this->active" avatar-size="md">
                    <x-slot:actions>
                        <a href="{{ $this->active ? route('ai.assistant.show', ['uuid' => $this->active['uuid']]) : route('ai.assistant') }}"
                            {{ wireNavigate() }} title="Open full page"
                            class="flex size-7 items-center justify-center rounded-md text-neutral-500 transition-[transform,background-color,color] duration-100 ease-out hover:bg-neutral-100 hover:text-black active:scale-95 dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.6" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M14 4h6m0 0v6m0-6L10 14M18 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h5" />
                            </svg>
                        </a>
                        <button type="button" wire:click="newThread" title="New chat"
                            class="flex size-7 items-center justify-center rounded-md text-neutral-500 transition-[transform,background-color,color] duration-100 ease-out hover:bg-neutral-100 hover:text-black active:scale-95 dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
                            </svg>
                        </button>
                        <button type="button" @click="open = false" title="Close"
                            class="flex size-7 items-center justify-center rounded-md text-neutral-500 transition-[transform,background-color,color] duration-100 ease-out hover:bg-neutral-100 hover:text-black active:scale-95 dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </x-slot:actions>
                </x-ai.conversation-header>
                <div class="min-h-0 flex-1 overflow-hidden bg-[var(--coollabs-base)]">
                    @if ($activeConversationId)
                        <div class="h-full px-3 pb-3">
                            <livewire:ai.thread :conversation-id="$activeConversationId"
                                :initial-pending="$pendingFirstMessage" :key="'widget-thread-'.$activeConversationId" />
                        </div>
                    @else
                        {{-- Empty composer; a conversation is created only on first send. --}}
                        <div x-data="{
                                draft: '',
                                sending: false,
                                suggestions: [
                                    'Give me an overview of my infrastructure',
                                    'What needs my attention right now?',
                                    'Show my recent failed deployments',
                                ],
                                submit() {
                                    const message = this.draft.trim();
                                    if (message === '' || this.sending) { return; }
                                    this.sending = true;
                                    this.$wire.startConversation(message, window.location.pathname + window.location.search);
                                },
                                pick(text) {
                                    if (this.sending) { return; }
                                    this.sending = true;
                                    this.$wire.startConversation(text, window.location.pathname + window.location.search);
                                },
                                autogrow() {
                                    const el = this.$refs.composer;
                                    if (! el) { return; }
                                    el.style.setProperty('height', 'auto', 'important');
                                    el.style.setProperty('height', Math.min(el.scrollHeight, 160) + 'px', 'important');
                                },
                            }" class="flex h-full flex-col">
                            <div class="flex min-h-0 flex-1 flex-col items-center justify-center gap-4 overflow-y-auto px-4 py-4 text-center">
                                <x-ai.avatar size="lg" variant="tint" />
                                <div class="flex flex-col gap-1">
                                    <div class="text-sm font-medium text-black dark:text-fg">How can I help?</div>
                                    <p class="text-[12px] leading-relaxed text-neutral-600 dark:text-fg-dim">Ask about
                                        your servers, deployments, databases, and more.</p>
                                </div>
                                <div class="flex w-full flex-col gap-1.5">
                                    <template x-for="suggestion in suggestions" :key="suggestion">
                                        <button type="button" x-text="suggestion" x-on:click="pick(suggestion)"
                                            x-bind:disabled="sending"
                                            class="w-full rounded-lg border border-neutral-200 bg-white/60 px-3 py-2 text-left text-[13px] text-neutral-600 transition-colors hover:border-coollabs/40 hover:text-black disabled:opacity-60 dark:border-white/10 dark:bg-white/[0.03] dark:text-fg-dim dark:hover:border-warning/40 dark:hover:text-fg"></button>
                                    </template>
                                </div>
                            </div>
                            <form x-on:submit.prevent="submit()" class="shrink-0 px-3 pb-3 pt-1">
                                <div
                                    class="flex items-end gap-2 rounded-2xl border border-neutral-200 bg-white px-2.5 py-2 shadow-[var(--shadow-dropdown)] transition-colors focus-within:border-coollabs dark:border-white/10 dark:bg-white/[0.03] dark:focus-within:border-warning">
                                    <textarea x-ref="composer" x-model="draft" rows="1" x-bind:disabled="sending"
                                        x-on:keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); submit(); }"
                                        x-on:input="autogrow()" placeholder="Message the assistant"
                                        class="min-h-7 max-h-[160px] w-full resize-none border-0 bg-transparent px-1 py-1 text-sm leading-relaxed text-black placeholder:text-neutral-500 focus:outline-none focus:ring-0 dark:text-fg dark:placeholder:text-fg-faint"></textarea>
                                    <button type="submit" title="Send" x-bind:disabled="!draft.trim() || sending"
                                        class="button button-highlighted !size-8 !min-h-8 shrink-0 rounded-full !px-0 transition-transform duration-100 ease-out active:scale-90 disabled:cursor-not-allowed disabled:opacity-40 disabled:active:scale-100">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none"
                                            viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5M5 12l7-7 7 7" />
                                        </svg>
                                    </button>
                                </div>
                            </form>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Launcher bubble: the chat glyph morphs to a minimize chevron on open (Intercom-style) --}}
            <button type="button" @click="open = !open; if (open) $wire.openThread()" :aria-expanded="open"
                :title="open ? 'Minimize assistant' : 'Ask the assistant'"
                class="relative flex size-14 items-center justify-center rounded-full bg-coollabs text-white shadow-dropdown transition-transform duration-150 ease-out will-change-transform hover:scale-105 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs focus-visible:ring-offset-2 dark:focus-visible:ring-offset-base motion-reduce:transition-none">
                <span aria-hidden="true"
                    class="absolute inset-0 grid place-items-center transition-[transform,opacity] duration-200 ease-[cubic-bezier(0.16,1,0.3,1)] motion-reduce:transition-none"
                    :class="open ? 'opacity-0 rotate-90 scale-75' : 'opacity-100 rotate-0 scale-100'">
                    <x-reicon name="feedback" class="size-6" />
                </span>
                <span aria-hidden="true" x-cloak
                    class="absolute inset-0 grid place-items-center transition-[transform,opacity] duration-200 ease-[cubic-bezier(0.16,1,0.3,1)] motion-reduce:transition-none"
                    :class="open ? 'opacity-100 rotate-0 scale-100' : 'opacity-0 -rotate-90 scale-75'">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-6" fill="none" viewBox="0 0 24 24"
                        stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                    </svg>
                </span>
            </button>
        </div>
    @endif
</div>
