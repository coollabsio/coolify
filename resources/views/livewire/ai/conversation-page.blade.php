<div x-data="{
    init() {
        if (! window.Echo) { return; }
        // Live-refresh the sidebar + header when a conversation is renamed
        // (AI title generation lands after the first reply).
        window.Echo.private('team.{{ currentTeam()->id }}')
            .listen('.assistant.conversation.renamed', () => $wire.$refresh());
    },
}"
    {{-- Full-bleed app shell: on desktop it fills the area below the top bar and
         right of the house nav, so nothing wastes space and there is a single
         (message) scrollbar instead of a page + content pair. --}}
    class="flex flex-col lg:fixed lg:top-12 lg:right-0 lg:bottom-0 lg:left-[var(--sidebar-w,14rem)] lg:z-30 lg:flex-row lg:overflow-hidden bg-neutral-50 dark:bg-app">
    <x-slot:title>Assistant | Coolify</x-slot>

    {{-- Conversation list --}}
    <aside
        class="flex w-full shrink-0 flex-col border-b border-neutral-200 bg-white lg:h-full lg:w-72 lg:border-b-0 lg:border-r dark:border-white/[0.06] dark:bg-panel">
        <div class="shrink-0 p-3">
            <x-forms.button wire:click="newThread" isHighlighted class="w-full justify-center gap-1.5">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
                </svg>
                New conversation
            </x-forms.button>
        </div>

        <nav aria-label="Conversations"
            class="flex max-h-[38vh] min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto px-2 pb-2 lg:max-h-none">
            @php $hasPinned = collect($this->threads)->contains('pinned', true); @endphp
            @forelse ($this->threads as $index => $thread)
                @if ($index === 0 && $hasPinned)
                    <div class="nav-section">Pinned</div>
                @elseif ($hasPinned && ! $thread['pinned'] && ($this->threads[$index - 1]['pinned'] ?? false))
                    <div class="nav-section mt-1">Conversations</div>
                @elseif ($index === 0)
                    <div class="nav-section">Conversations</div>
                @endif

                <div wire:key="thread-{{ $thread['id'] }}" class="group relative" x-data="{ menuOpen: false }"
                    :class="menuOpen && 'z-20'" x-on:click.outside="menuOpen = false"
                    x-on:keydown.escape.window="menuOpen = false">
                    @if ($editingId === $thread['id'])
                        <form wire:submit="rename" class="px-0.5 py-0.5">
                            <input type="text" wire:model="editingTitle" x-init="$el.focus(); $el.select()"
                                x-on:keydown.escape.stop.prevent="$wire.cancelRename()" x-on:blur="$wire.rename()"
                                class="h-8 w-full rounded-md border border-coollabs bg-white px-2.5 text-[13px] font-medium text-black focus:outline-none focus:ring-0 dark:border-warning dark:bg-white/[0.06] dark:text-fg" />
                        </form>
                    @else
                        <a href="{{ route('ai.assistant.show', ['uuid' => $thread['uuid']]) }}" wire:navigate @class([
                            'menu-item pr-8',
                            'menu-item-active' => $activeConversationId === $thread['id'],
                        ])>
                            @if ($thread['pinned'])
                                <x-reicon name="pin" class="menu-item-icon" />
                            @else
                                <x-reicon name="feedback" class="menu-item-icon" />
                            @endif
                            <span class="menu-item-label text-left">{{ $thread['title'] }}</span>
                            @if ($thread['visibility'] === 'team')
                                <span class="shrink-0 text-[11px] font-medium text-nav-muted">Team</span>
                            @endif
                        </a>

                        @if ($thread['mine'])
                            <button type="button" x-on:click.stop="menuOpen = ! menuOpen" :aria-expanded="menuOpen"
                                title="Conversation options" @class([
                                    'absolute right-1 top-1/2 flex size-6 -translate-y-1/2 items-center justify-center rounded-md text-nav-muted transition-[opacity,background-color,color] hover:bg-black/[0.06] hover:text-nav-active dark:hover:bg-white/[0.08]',
                                    'opacity-0 focus-visible:opacity-100 group-hover:opacity-100' => $activeConversationId !== $thread['id'],
                                ])>
                                <svg class="size-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <circle cx="12" cy="5" r="1.6" />
                                    <circle cx="12" cy="12" r="1.6" />
                                    <circle cx="12" cy="19" r="1.6" />
                                </svg>
                            </button>
                            <div x-show="menuOpen" x-cloak x-transition.origin.top.right
                                class="listbox-panel top-full! right-0! left-auto! mt-1! w-44!" role="menu">
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    wire:click="startRename({{ $thread['id'] }})" x-on:click="menuOpen = false"
                                    role="menuitem">
                                    <x-reicon name="pencil" class="size-4 shrink-0 opacity-70" />
                                    Rename
                                </button>
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    wire:click="togglePin({{ $thread['id'] }})" x-on:click="menuOpen = false"
                                    role="menuitem">
                                    <x-reicon name="pin" class="size-4 shrink-0 opacity-70" />
                                    {{ $thread['pinned'] ? 'Unpin' : 'Pin' }}
                                </button>
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    wire:click="archive({{ $thread['id'] }})" x-on:click="menuOpen = false"
                                    role="menuitem">
                                    <x-reicon name="archive" class="size-4 shrink-0 opacity-70" />
                                    Archive
                                </button>
                                <button type="button"
                                    class="listbox-option justify-start! gap-2.5! text-error! hover:bg-error/10!"
                                    wire:click="deleteThread({{ $thread['id'] }})" wire:confirm="Delete this conversation?"
                                    x-on:click="menuOpen = false" role="menuitem">
                                    <x-reicon name="trash" class="size-4 shrink-0 opacity-70" />
                                    Delete
                                </button>
                            </div>
                        @endif
                    @endif
                </div>
            @empty
                <p class="px-2.5 py-6 text-[13px] text-nav-muted">No conversations yet.</p>
            @endforelse
        </nav>

        @if (count($this->archivedThreads) > 0)
            <div class="shrink-0 border-t border-black/5 px-2 py-1 dark:border-white/5">
                <button type="button" wire:click="toggleArchived" :aria-expanded="@js($showArchived)"
                    class="flex w-full items-center justify-between gap-2 rounded-md px-2.5 py-1.5 text-[11px] font-medium text-nav-muted transition-colors hover:bg-black/[0.04] hover:text-nav-active dark:hover:bg-white/[0.05]">
                    <span class="flex items-center gap-1.5">
                        <x-reicon name="archive" class="size-3.5" />
                        Archived ({{ count($this->archivedThreads) }})
                    </span>
                    <svg class="size-3 shrink-0 transition-transform @if ($showArchived) rotate-90 @endif"
                        viewBox="0 0 12 12" fill="none" aria-hidden="true">
                        <path d="m4.5 3 3 3-3 3" stroke="currentColor" stroke-width="1.25" stroke-linecap="round"
                            stroke-linejoin="round" />
                    </svg>
                </button>

                @if ($showArchived)
                    <div class="mt-0.5 flex max-h-[30vh] flex-col gap-0.5 overflow-y-auto">
                        @foreach ($this->archivedThreads as $thread)
                            <div wire:key="arch-{{ $thread['id'] }}" class="group relative" x-data="{ menuOpen: false }"
                                :class="menuOpen && 'z-20'" x-on:click.outside="menuOpen = false"
                                x-on:keydown.escape.window="menuOpen = false">
                                <a href="{{ route('ai.assistant.show', ['uuid' => $thread['uuid']]) }}" wire:navigate
                                    @class([
                                        'menu-item pr-8',
                                        'menu-item-active' => $activeConversationId === $thread['id'],
                                    ])>
                                    <x-reicon name="archive" class="menu-item-icon opacity-60" />
                                    <span class="menu-item-label text-left">{{ $thread['title'] }}</span>
                                    @if ($thread['visibility'] === 'team')
                                        <span class="shrink-0 text-[11px] font-medium text-nav-muted">Team</span>
                                    @endif
                                </a>

                                @if ($thread['mine'])
                                    <button type="button" x-on:click.stop="menuOpen = ! menuOpen"
                                        :aria-expanded="menuOpen" title="Conversation options" @class([
                                            'absolute right-1 top-1/2 flex size-6 -translate-y-1/2 items-center justify-center rounded-md text-nav-muted transition-[opacity,background-color,color] hover:bg-black/[0.06] hover:text-nav-active dark:hover:bg-white/[0.08]',
                                            'opacity-0 focus-visible:opacity-100 group-hover:opacity-100' => $activeConversationId !== $thread['id'],
                                        ])>
                                        <svg class="size-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <circle cx="12" cy="5" r="1.6" />
                                            <circle cx="12" cy="12" r="1.6" />
                                            <circle cx="12" cy="19" r="1.6" />
                                        </svg>
                                    </button>
                                    <div x-show="menuOpen" x-cloak x-transition.origin.top.right
                                        class="listbox-panel bottom-full! right-0! left-auto! mb-1! w-44!" role="menu">
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            wire:click="unarchive({{ $thread['id'] }})" x-on:click="menuOpen = false"
                                            role="menuitem">
                                            <x-reicon name="archive" class="size-4 shrink-0 opacity-70" />
                                            Unarchive
                                        </button>
                                        <button type="button"
                                            class="listbox-option justify-start! gap-2.5! text-error! hover:bg-error/10!"
                                            wire:click="deleteThread({{ $thread['id'] }})"
                                            wire:confirm="Delete this conversation?" x-on:click="menuOpen = false"
                                            role="menuitem">
                                            <x-reicon name="trash" class="size-4 shrink-0 opacity-70" />
                                            Delete
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </aside>

    {{-- Conversation pane: header snug under the top bar, thread fills the rest
         with the composer pinned and the messages as the only scroll region. --}}
    <section class="flex h-[70vh] min-h-0 min-w-0 flex-col lg:h-full lg:flex-1">
        @if ($activeConversationId && $this->active)
            <x-ai.conversation-header :title="$this->active['title']"
                class="border-b border-neutral-200 bg-white dark:border-white/[0.06] dark:bg-panel">
                @if ($this->active['visibility'] === 'team')
                    <x-slot:badge>
                        <span
                            class="shrink-0 rounded-md bg-coollabs/10 px-1.5 py-0.5 text-[11px] font-medium text-coollabs dark:bg-warning/10 dark:text-warning">Team</span>
                    </x-slot:badge>
                @endif
                <x-slot:actions>
                    @if ($this->active['mine'])
                        {{-- Share / unshare, each behind a small confirm popover. --}}
                        <div x-data="{ open: false }" class="relative">
                            <button type="button" x-on:click="open = ! open" :aria-expanded="open"
                                class="flex h-8 items-center gap-1.5 rounded-md px-2 text-[13px] font-medium text-neutral-600 transition-colors hover:bg-black/[0.04] hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                                <x-reicon name="teams" class="size-4" />
                                {{ $this->active['visibility'] === 'team' ? 'Shared' : 'Share' }}
                            </button>
                            <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition.origin.top.right
                                class="surface-popover absolute right-0 top-full z-50 mt-2 w-64 rounded-lg p-3 text-left">
                                @if ($this->active['visibility'] === 'team')
                                    <p class="text-[13px] leading-relaxed text-neutral-600 dark:text-fg-dim">
                                        Shared with your team. Everyone on the team can view it. Make it
                                        private again?</p>
                                    <div class="mt-3 flex justify-end gap-2">
                                        <button type="button" x-on:click="open = false"
                                            class="h-8 rounded-md px-2.5 text-[13px] font-medium text-neutral-600 transition-colors hover:bg-black/[0.04] hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">Cancel</button>
                                        <x-forms.button wire:click="unshare({{ $activeConversationId }})"
                                            x-on:click="open = false">Make private</x-forms.button>
                                    </div>
                                @else
                                    <p class="text-[13px] leading-relaxed text-neutral-600 dark:text-fg-dim">
                                        Share this conversation with your team? Everyone on the team will be
                                        able to view it.</p>
                                    <div class="mt-3 flex justify-end gap-2">
                                        <button type="button" x-on:click="open = false"
                                            class="h-8 rounded-md px-2.5 text-[13px] font-medium text-neutral-600 transition-colors hover:bg-black/[0.04] hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">Cancel</button>
                                        <x-forms.button wire:click="share({{ $activeConversationId }})"
                                            x-on:click="open = false">Share with team</x-forms.button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                    <button type="button" wire:click="deleteThread({{ $activeConversationId }})"
                        wire:confirm="Delete this conversation?" title="Delete conversation"
                        class="flex size-8 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-error/10 hover:text-error dark:text-fg-faint">
                        <x-reicon name="trash" class="size-4" />
                    </button>
                </x-slot:actions>
            </x-ai.conversation-header>

            <div class="min-h-0 flex-1">
                <livewire:ai.thread :conversation-id="$activeConversationId" :wide="true"
                    :initial-pending="$pendingFirstMessage" :key="'thread-'.$activeConversationId" />
            </div>
        @else
            {{-- Default page: a welcoming ChatGPT-style start screen with the composer
                 visible and default prompts. Sending starts a new conversation. --}}
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
                        el.style.setProperty('height', Math.min(el.scrollHeight, 200) + 'px', 'important');
                    },
                }"
                class="flex h-full min-h-0 flex-col">
                {{-- Welcome + prompts fill the message area; the composer stays pinned
                     at the bottom in its usual position. --}}
                <div class="flex min-h-0 flex-1 flex-col items-center justify-center gap-6 overflow-y-auto px-4 py-6">
                    <div class="flex w-full max-w-2xl flex-col items-center gap-6">
                        <div class="flex flex-col items-center gap-3 text-center">
                            <x-ai.avatar size="lg" variant="tint" />
                            <h1 class="text-xl font-semibold tracking-tight text-black dark:text-fg">How can I help?</h1>
                            <p class="max-w-[44ch] text-sm leading-relaxed text-neutral-600 dark:text-fg-dim">Ask about
                                your servers, deployments, databases, and more.</p>
                        </div>
                        <div class="flex w-full max-w-md flex-col gap-2">
                            <template x-for="suggestion in suggestions" :key="suggestion">
                                <button type="button" x-text="suggestion" x-on:click="pick(suggestion)"
                                    x-bind:disabled="sending"
                                    class="w-full rounded-xl border border-neutral-200 bg-white/60 px-3.5 py-2.5 text-left text-sm text-neutral-600 transition-colors hover:border-coollabs/40 hover:text-black disabled:opacity-60 dark:border-white/10 dark:bg-white/[0.03] dark:text-fg-dim dark:hover:border-warning/40 dark:hover:text-fg"></button>
                            </template>
                        </div>
                    </div>
                </div>

                <form x-on:submit.prevent="submit()" class="px-4 pb-1 pt-2">
                    <div class="mx-auto w-full max-w-3xl">
                        <div
                            class="flex items-end gap-2 rounded-2xl border border-neutral-200 bg-white px-2.5 py-2 shadow-[var(--shadow-dropdown)] transition-colors focus-within:border-coollabs dark:border-white/10 dark:bg-white/[0.03] dark:focus-within:border-warning">
                            <textarea x-ref="composer" x-model="draft" rows="1" x-bind:disabled="sending" autofocus
                                x-on:keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); submit(); }"
                                x-on:input="autogrow()" placeholder="Message the assistant"
                                class="min-h-7 max-h-[200px] w-full resize-none border-0 bg-transparent px-1 py-1 text-sm leading-relaxed text-black placeholder:text-neutral-500 focus:outline-none focus:ring-0 dark:text-fg dark:placeholder:text-fg-faint"></textarea>
                            <button type="submit" title="Send" x-bind:disabled="!draft.trim() || sending"
                                class="button button-highlighted !size-8 !min-h-8 shrink-0 rounded-full !px-0 transition-transform duration-100 ease-out active:scale-90 disabled:cursor-not-allowed disabled:opacity-40 disabled:active:scale-100">
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24"
                                    stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5M5 12l7-7 7 7" />
                                </svg>
                            </button>
                        </div>
                        <p class="mt-2 text-center text-[11px] text-neutral-500 dark:text-fg-faint">The assistant can
                            make mistakes. It always asks before making changes.</p>
                    </div>
                </form>
            </div>
        @endif
    </section>
</div>
