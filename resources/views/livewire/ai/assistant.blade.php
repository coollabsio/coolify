<div>
    @if ($this->enabled)
        <x-slide-over>
            <button type="button" x-data
                x-on:click="slideOverOpen = true; $wire.openThread()"
                x-on:open-assistant.window="slideOverOpen = true; $wire.openThread()"
                title="Assistant"
                class="flex items-center justify-center w-8 h-8 rounded-md hover:bg-black/5 dark:hover:bg-white/5 focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning">
                <x-reicon name="feedback" class="w-4 h-4" />
            </button>
            <x-slot:title>Assistant</x-slot:title>
            <x-slot:content>
                @if ($activeConversationId)
                    <div class="h-full">
                        <livewire:ai.thread :conversation-id="$activeConversationId" :key="'slideover-thread-'.$activeConversationId" />
                    </div>
                @endif
            </x-slot:content>
        </x-slide-over>
    @endif
</div>
