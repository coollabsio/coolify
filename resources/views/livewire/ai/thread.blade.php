<div class="flex flex-col h-full min-h-0"
    x-data="assistantThread({
        channel: 'ai-conversation.{{ $this->conversation->uuid }}',
        initialBusy: @js($this->busy),
    })"
    x-on:assistant-refresh.window="$wire.$refresh()"
    x-on:assistant-idle.window="onIdle()">

    <div class="flex-1 min-h-0 overflow-y-auto scrollbar-thin scrollbar-track-transparent scrollbar-thumb-neutral-300 dark:scrollbar-thumb-white/10 px-1 py-4 space-y-5"
        x-ref="scroll">
        @forelse ($this->messages as $message)
            @if ($message['role'] === 'assistant')
                <div wire:key="msg-{{ $message['id'] }}" class="flex flex-col gap-1.5">
                    <div class="flex items-center gap-1.5 text-xs text-fg-faint">
                        <span
                            class="flex size-4 items-center justify-center rounded bg-coollabs/10 text-coollabs dark:bg-warning/10 dark:text-warning">
                            <x-reicon name="feedback" class="size-2.5" />
                        </span>
                        Assistant
                    </div>
                    <div class="prose-assistant whitespace-pre-wrap text-sm leading-relaxed"
                        x-html="renderMarkdown(@js($message['content']))"></div>
                </div>
            @else
                <div wire:key="msg-{{ $message['id'] }}" class="flex flex-col items-end gap-1">
                    @if (filled($message['author']))
                        <span class="px-1 text-xs text-fg-faint">{{ $message['author'] }}</span>
                    @endif
                    <div x-text="@js($message['content'])"
                        class="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-md bg-coollabs/10 px-3 py-2 text-sm leading-relaxed dark:bg-warning/10">
                    </div>
                </div>
            @endif
        @empty
            {{-- Welcome + context-aware suggestions (hidden the moment a message is in flight). --}}
            <div x-show="!streaming && !partial && !thinking && pending.length === 0"
                class="flex h-full flex-col items-center justify-center gap-5 px-2 text-center">
                <div class="flex flex-col items-center gap-2">
                    <span
                        class="flex size-11 items-center justify-center rounded-2xl bg-coollabs/10 text-coollabs dark:bg-warning/10 dark:text-warning">
                        <x-reicon name="feedback" class="size-5" />
                    </span>
                    <div class="text-base font-medium text-fg">How can I help?</div>
                    <p class="max-w-[34ch] text-sm text-fg-dim">I can inspect servers, deployments, databases, and
                        config. Destructive actions always ask first.</p>
                </div>
                <div class="flex w-full flex-col gap-1.5">
                    <template x-for="suggestion in suggestions" :key="suggestion">
                        <button type="button" x-text="suggestion" x-on:click="sendSuggestion(suggestion)"
                            class="w-full rounded-xl border border-neutral-200 bg-white/60 px-3 py-2 text-left text-sm text-fg-dim transition hover:border-coollabs/40 hover:text-fg dark:border-white/10 dark:bg-white/[0.03] dark:hover:border-warning/40"></button>
                    </template>
                </div>
            </div>
        @endforelse

        {{-- Optimistic user messages: shown instantly, cleared once the real rows load. --}}
        <template x-for="(text, index) in pending" :key="'pending-' + index">
            <div class="flex flex-col items-end gap-1">
                <div
                    class="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-md bg-coollabs/10 px-3 py-2 text-sm leading-relaxed dark:bg-warning/10"
                    x-text="text"></div>
            </div>
        </template>

        {{-- "Assistant is typing" — bridges the gap before the first streamed token. --}}
        <div x-show="thinking && !partial" class="flex flex-col gap-1.5">
            <div class="flex items-center gap-1.5 text-xs text-fg-faint">
                <span
                    class="flex size-4 items-center justify-center rounded bg-coollabs/10 text-coollabs dark:bg-warning/10 dark:text-warning">
                    <x-reicon name="feedback" class="size-2.5" />
                </span>
                Assistant
            </div>
            <div class="flex gap-1 px-0.5 py-1">
                <span class="size-1.5 rounded-full bg-fg-faint animate-bounce [animation-delay:-0.3s]"></span>
                <span class="size-1.5 rounded-full bg-fg-faint animate-bounce [animation-delay:-0.15s]"></span>
                <span class="size-1.5 rounded-full bg-fg-faint animate-bounce"></span>
            </div>
        </div>

        {{-- Live streaming buffer (appended by Echo, seeded from the cached partial) --}}
        <div x-show="streaming || partial" wire:ignore class="flex flex-col gap-1.5">
            <div class="flex items-center gap-1.5 text-xs text-fg-faint">
                <span
                    class="flex size-4 items-center justify-center rounded bg-coollabs/10 text-coollabs dark:bg-warning/10 dark:text-warning">
                    <x-reicon name="feedback" class="size-2.5" />
                </span>
                Assistant
            </div>
            <div class="prose-assistant whitespace-pre-wrap text-sm leading-relaxed" x-html="renderMarkdown(partial)">
            </div>
            <span x-show="streaming"
                class="inline-block w-1.5 h-4 align-middle bg-coollabs dark:bg-warning animate-pulse"></span>
        </div>
    </div>

    {{-- Approval cards: name the exact target + arguments --}}
    @foreach ($this->pendingApprovals as $approval)
        <div wire:key="approval-{{ $approval['id'] }}"
            class="mx-1 mb-2 rounded-xl ring-1 ring-amber-300/60 bg-amber-50 dark:bg-amber-950/30 dark:ring-amber-500/30 px-3 py-2">
            <div class="text-sm font-medium text-amber-900 dark:text-amber-200">Approval required</div>
            <div class="mt-0.5 text-sm text-amber-800 dark:text-amber-100/90">{{ $approval['reason'] }}</div>
            <div class="mt-2 flex gap-2">
                <x-forms.button wire:click="approve('{{ $approval['id'] }}')" wire:loading.attr="disabled">Approve</x-forms.button>
                <x-forms.button isError wire:click="reject('{{ $approval['id'] }}')" wire:loading.attr="disabled">Reject</x-forms.button>
            </div>
        </div>
    @endforeach

    {{-- Composer: app input + button utilities, sent optimistically. --}}
    <form x-on:submit.prevent="submit()" class="mt-2 pt-3 border-t border-black/5 dark:border-white/5">
        <div class="flex items-end gap-2">
            <textarea x-ref="composer" x-model="draft" rows="1" x-bind:disabled="busy"
                x-on:keydown.enter.exact.prevent="submit()" x-on:input="autogrow()"
                placeholder="Message the assistant"
                class="input w-full resize-none !h-auto min-h-9 max-h-[140px] leading-relaxed"></textarea>
            <template x-if="!busy">
                <button type="submit" title="Send"
                    class="button button-highlighted !size-9 !min-h-9 !px-0 transition-transform duration-100 ease-out active:scale-90 disabled:active:scale-100">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24"
                        stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5M5 12l7-7 7 7" />
                    </svg>
                </button>
            </template>
            <template x-if="busy">
                <button type="button" x-on:click="stopTurn()" title="Stop"
                    class="button !size-9 !min-h-9 !px-0 transition-transform duration-100 ease-out active:scale-90">
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5" viewBox="0 0 24 24" fill="currentColor">
                        <rect x="6" y="6" width="12" height="12" rx="2" />
                    </svg>
                </button>
            </template>
        </div>
    </form>

    @script
        <script>
            Alpine.data('assistantThread', ({
                channel,
                initialBusy
            }) => ({
                draft: '',
                partial: '',
                streaming: false,
                thinking: initialBusy ?? false,
                busy: initialBusy ?? false,
                pending: [],
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
                            this.thinking = false;
                            this.streaming = true;
                            this.busy = true;
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
                submit() {
                    this.dispatchMessage(this.draft);
                    this.draft = '';
                    this.resetHeight();
                },
                sendSuggestion(text) {
                    this.dispatchMessage(text);
                },
                dispatchMessage(text) {
                    const message = (text ?? '').trim();
                    if (message === '' || this.busy) {
                        return;
                    }
                    this.pending.push(message);
                    this.busy = true;
                    this.thinking = true;
                    this.$nextTick(() => this.scrollToEnd());
                    this.$wire.sendPrompt(message, window.location.pathname + window.location.search);
                },
                stopTurn() {
                    this.busy = false;
                    this.thinking = false;
                    this.$wire.stop();
                },
                finish() {
                    this.$wire.$refresh().then(() => {
                        this.pending = [];
                        this.partial = '';
                        this.streaming = false;
                        this.thinking = false;
                        this.busy = false;
                        this.lastSeq = 0;
                        this.$nextTick(() => this.scrollToEnd());
                    });
                },
                onIdle() {
                    this.pending = [];
                    this.busy = false;
                    this.thinking = false;
                    this.streaming = false;
                },
                renderMarkdown(text) {
                    const src = (text ?? '').replace(/\r\n/g, '\n');
                    const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    const inline = (s) => esc(s)
                        .replace(/`([^`]+)`/g, (_, c) => `<code>${c}</code>`)
                        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
                        .replace(/__([^_]+)__/g, '<strong>$1</strong>')
                        .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>')
                        .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g,
                            '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
                    const splitRow = (row) => row.trim().replace(/^\|/, '').replace(/\|$/, '')
                        .split('|').map((cell) => cell.trim());
                    const isSeparatorRow = (row) => {
                        if (! row || ! row.includes('|') || ! row.includes('-')) {
                            return false;
                        }
                        const cells = splitRow(row);
                        return cells.length > 0 && cells.every((cell) => /^:?-{1,}:?$/.test(cell));
                    };

                    const lines = src.split('\n');
                    let html = '';
                    let i = 0;
                    let list = null;
                    const closeList = () => {
                        if (list) {
                            html += `</${list}>`;
                            list = null;
                        }
                    };
                    while (i < lines.length) {
                        const line = lines[i];
                        const fence = line.match(/^```/);
                        if (fence) {
                            closeList();
                            const buf = [];
                            i++;
                            while (i < lines.length && !/^```\s*$/.test(lines[i])) {
                                buf.push(lines[i]);
                                i++;
                            }
                            i++;
                            html += `<pre><code>${esc(buf.join('\n'))}</code></pre>`;
                            continue;
                        }
                        if (line.includes('|') && i + 1 < lines.length && isSeparatorRow(lines[i + 1])) {
                            closeList();
                            const headers = splitRow(line);
                            i += 2;
                            let rows = '';
                            while (i < lines.length && lines[i].includes('|') && lines[i].trim() !== '') {
                                const cells = splitRow(lines[i]);
                                rows += '<tr>' + headers.map((_, idx) =>
                                    `<td>${inline(cells[idx] ?? '')}</td>`).join('') + '</tr>';
                                i++;
                            }
                            const head = '<tr>' + headers.map((h) => `<th>${inline(h)}</th>`).join('') + '</tr>';
                            html +=
                                `<div class="assistant-table-wrap"><table><thead>${head}</thead><tbody>${rows}</tbody></table></div>`;
                            continue;
                        }
                        if (/^\s*([-*_])\1{2,}\s*$/.test(line)) {
                            closeList();
                            html += '<hr>';
                            i++;
                            continue;
                        }
                        const heading = line.match(/^(#{1,6})\s+(.*)$/);
                        if (heading) {
                            closeList();
                            const level = Math.min(heading[1].length + 2, 6);
                            html += `<h${level}>${inline(heading[2])}</h${level}>`;
                            i++;
                            continue;
                        }
                        if (/^>\s?/.test(line)) {
                            closeList();
                            html += `<blockquote>${inline(line.replace(/^>\s?/, ''))}</blockquote>`;
                            i++;
                            continue;
                        }
                        if (/^\s*[-*+]\s+/.test(line)) {
                            if (list !== 'ul') {
                                closeList();
                                html += '<ul>';
                                list = 'ul';
                            }
                            html += `<li>${inline(line.replace(/^\s*[-*+]\s+/, ''))}</li>`;
                            i++;
                            continue;
                        }
                        if (/^\s*\d+\.\s+/.test(line)) {
                            if (list !== 'ol') {
                                closeList();
                                html += '<ol>';
                                list = 'ol';
                            }
                            html += `<li>${inline(line.replace(/^\s*\d+\.\s+/, ''))}</li>`;
                            i++;
                            continue;
                        }
                        if (line.trim() === '') {
                            closeList();
                            i++;
                            continue;
                        }
                        closeList();
                        const para = [line];
                        i++;
                        while (i < lines.length && lines[i].trim() !== '' &&
                            !/^(#{1,6}\s|>\s?|\s*[-*+]\s|\s*\d+\.\s|```)/.test(lines[i])) {
                            para.push(lines[i]);
                            i++;
                        }
                        html += `<p>${inline(para.join('\n')).replace(/\n/g, '<br>')}</p>`;
                    }
                    closeList();
                    return window.DOMPurify ? window.DOMPurify.sanitize(html) : html;
                },
                autogrow() {
                    const el = this.$refs.composer;
                    el.style.height = 'auto';
                    el.style.height = Math.min(el.scrollHeight, 140) + 'px';
                },
                resetHeight() {
                    const el = this.$refs.composer;
                    if (el) {
                        el.style.height = '';
                    }
                },
                scrollToEnd() {
                    const el = this.$refs.scroll;
                    if (el) {
                        el.scrollTop = el.scrollHeight;
                    }
                },
                get suggestions() {
                    const path = window.location.pathname;
                    if (path.startsWith('/server')) {
                        return [
                            'List my servers and their status',
                            'Which servers are unreachable?',
                            'Show CPU and disk usage across my servers',
                        ];
                    }
                    if (path.includes('/database')) {
                        return [
                            'List my databases and their status',
                            'Which databases have backups configured?',
                            'Show recent backup results',
                        ];
                    }
                    if (path.startsWith('/project') || path.includes('/application')) {
                        return [
                            'Show my latest deployments',
                            'Which deployments failed recently?',
                            'List my running applications',
                        ];
                    }
                    return [
                        'Give me an overview of my infrastructure',
                        'What needs my attention right now?',
                        'Show my recent failed deployments',
                    ];
                },
            }));
        </script>
    @endscript
</div>
