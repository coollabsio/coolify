<div class="flex flex-col h-full min-h-0"
    x-data="assistantThread({
        channel: 'ai-conversation.{{ $this->conversation->uuid }}',
    })"
    x-on:assistant-refresh.window="$wire.$refresh()">

    <div class="flex-1 min-h-0 overflow-y-auto scrollbar px-1 py-4 space-y-4" x-ref="scroll">
        @forelse ($this->messages as $message)
            <div wire:key="msg-{{ $message['id'] }}"
                @class([
                    'rounded-lg px-3 py-2 text-sm leading-relaxed ring-1',
                    'bg-white dark:bg-coolgray-100 ring-black/5 dark:ring-white/5' => $message['role'] === 'assistant',
                    'bg-coollabs/5 dark:bg-warning/5 ring-coollabs/10 dark:ring-warning/10' => $message['role'] === 'user',
                ])>
                <div class="mb-1 text-xs text-neutral-500 dark:text-neutral-400">
                    {{ $message['role'] === 'user' ? ($message['author'] ?? 'You') : 'Assistant' }}
                </div>
                <div class="prose-assistant whitespace-pre-wrap" x-html="renderMarkdown(@js($message['content']))"></div>
            </div>
        @empty
            <div class="flex h-full flex-col items-center justify-center gap-2 text-center text-sm text-neutral-500">
                <div class="text-base text-neutral-700 dark:text-neutral-300">Ask about your infrastructure</div>
                <div>Inspect servers, deploy, edit config, or clean up. Destructive actions always ask first.</div>
            </div>
        @endforelse

        {{-- Live streaming buffer (appended by Echo, seeded from the cached partial) --}}
        <div x-show="streaming || partial" wire:ignore
            class="rounded-lg px-3 py-2 text-sm leading-relaxed ring-1 bg-white dark:bg-coolgray-100 ring-black/5 dark:ring-white/5">
            <div class="mb-1 text-xs text-neutral-500 dark:text-neutral-400">Assistant</div>
            <div class="prose-assistant whitespace-pre-wrap" x-html="renderMarkdown(partial)"></div>
            <span x-show="streaming" class="inline-block w-1.5 h-4 align-middle bg-coollabs dark:bg-warning animate-pulse"></span>
        </div>
    </div>

    {{-- Approval cards: name the exact target + arguments --}}
    @foreach ($this->pendingApprovals as $approval)
        <div wire:key="approval-{{ $approval['id'] }}"
            class="mx-1 mb-2 rounded-lg ring-1 ring-amber-300/60 bg-amber-50 dark:bg-amber-950/30 dark:ring-amber-500/30 px-3 py-2 shadow-dropdown">
            <div class="text-sm font-medium text-amber-900 dark:text-amber-200">Approval required</div>
            <div class="mt-0.5 text-sm text-amber-800 dark:text-amber-100/90">{{ $approval['reason'] }}</div>
            <div class="mt-2 flex gap-2">
                <x-forms.button wire:click="approve('{{ $approval['id'] }}')" wire:loading.attr="disabled">Approve</x-forms.button>
                <x-forms.button isError wire:click="reject('{{ $approval['id'] }}')" wire:loading.attr="disabled">Reject</x-forms.button>
            </div>
        </div>
    @endforeach

    <form wire:submit="send" class="mt-2 flex items-end gap-2 border-t border-black/5 dark:border-white/5 pt-3">
        <textarea wire:model="composerMessage" rows="2" @disabled($this->busy)
            x-on:keydown.enter.exact.prevent="$wire.send()"
            placeholder="Message the assistant"
            class="flex-1 resize-none rounded-lg bg-white dark:bg-coolgray-100 ring-1 ring-black/10 dark:ring-white/10 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-coollabs dark:focus:ring-warning"></textarea>
        <template x-if="!$wire.busy">
            <x-forms.button type="submit">Send</x-forms.button>
        </template>
        <template x-if="$wire.busy">
            <x-forms.button type="button" wire:click="stop">Stop</x-forms.button>
        </template>
    </form>

    @script
    <script>
        Alpine.data('assistantThread', ({ channel }) => ({
            partial: '',
            streaming: false,
            lastSeq: 0,
            init() {
                this.partial = @js($this->partial());
                if (! window.Echo) {
                    return;
                }
                window.Echo.private(channel)
                    .listen('.assistant.delta', (e) => {
                        if (e.sequence > this.lastSeq + 1 && this.lastSeq !== 0) {
                            this.$dispatch('assistant-refresh');
                            this.partial = @js($this->partial());
                        }
                        this.lastSeq = e.sequence;
                        this.streaming = true;
                        this.partial += e.delta;
                        this.$nextTick(() => this.scrollToEnd());
                    })
                    .listen('.assistant.completed', () => this.finish())
                    .listen('.assistant.approval', () => this.finish())
                    .listen('.assistant.failed', (e) => {
                        this.finish();
                        this.$dispatch('error', e.message ?? 'The assistant turn failed.');
                    });
            },
            finish() {
                this.streaming = false;
                this.partial = '';
                this.lastSeq = 0;
                this.$dispatch('assistant-refresh');
            },
            renderMarkdown(text) {
                const html = window.marked ? window.marked.parse(text ?? '') : (text ?? '');
                return window.DOMPurify ? window.DOMPurify.sanitize(html) : html;
            },
            scrollToEnd() {
                const el = this.$refs.scroll;
                if (el) { el.scrollTop = el.scrollHeight; }
            },
        }));
    </script>
    @endscript
</div>
