<div>
    <x-slot:title>Assistant | Coolify</x-slot>
    <div class="flex h-[calc(100vh-9rem)] gap-4">
        <aside class="w-64 shrink-0 flex flex-col gap-2 border-r border-black/5 dark:border-white/5 pr-3">
            <x-forms.button wire:click="newThread" class="w-full justify-center">New conversation</x-forms.button>
            <div class="flex-1 overflow-y-auto scrollbar space-y-1">
                @foreach ($this->threads as $thread)
                    <button type="button" wire:key="thread-{{ $thread['id'] }}" wire:click="open({{ $thread['id'] }})"
                        @class([
                            'group w-full text-left rounded-md px-2 py-1.5 text-sm ring-1',
                            'bg-black/5 dark:bg-white/10 ring-black/10 dark:ring-white/10' => $activeConversationId === $thread['id'],
                            'ring-transparent hover:bg-black/5 dark:hover:bg-white/5' => $activeConversationId !== $thread['id'],
                        ])>
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate">{{ $thread['title'] }}</span>
                            @if ($thread['visibility'] === 'team')
                                <span class="text-xs text-neutral-500">team</span>
                            @endif
                        </div>
                    </button>
                @endforeach
            </div>
        </aside>

        <section class="flex-1 min-w-0 flex flex-col">
            @if ($activeConversationId)
                <div class="mb-2 flex items-center justify-end gap-2">
                    <x-forms.button wire:click="share({{ $activeConversationId }})">Share with team</x-forms.button>
                    <x-forms.button isError wire:click="deleteThread({{ $activeConversationId }})"
                        wire:confirm="Delete this conversation?">Delete</x-forms.button>
                </div>
                <div class="flex-1 min-h-0">
                    <livewire:ai.thread :conversation-id="$activeConversationId" :key="'thread-'.$activeConversationId" />
                </div>
            @else
                <div class="flex h-full items-center justify-center text-sm text-neutral-500">
                    Start a new conversation to begin.
                </div>
            @endif
        </section>
    </div>
</div>
