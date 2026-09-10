<div class="flex flex-col h-full min-h-0"
    x-data="assistantThread({
        channel: 'ai-conversation.{{ $this->conversation->uuid }}',
        initialBusy: @js($this->busy),
        initialPending: @js($initialPending),
        viewer: @js($this->viewer),
    })"
    x-on:assistant-refresh.window="$wire.$refresh()"
    x-on:assistant-idle.window="onIdle()">

    <div @class([
        'flex flex-1 min-h-0 flex-col overflow-y-auto scrollbar-thin scrollbar-track-transparent scrollbar-thumb-neutral-300 dark:scrollbar-thumb-white/10',
        'px-4 py-6' => $wide,
        'px-1 py-4' => ! $wide,
    ]) x-ref="scroll">
        <div @class([
            'mx-auto flex w-full flex-1 flex-col',
            'max-w-3xl gap-6' => $wide,
            'gap-5' => ! $wide,
        ])>
        @forelse ($this->messages as $message)
            @if ($message['role'] === 'note')
                <div wire:key="msg-{{ $message['id'] }}" class="flex justify-center py-1">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full bg-black/[0.04] px-2.5 py-1 text-[11px] font-medium text-neutral-500 dark:bg-white/[0.05] dark:text-fg-faint">
                        <svg class="size-3 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
                            <path d="m9 9 6 6M15 9l-6 6" stroke="currentColor" stroke-width="1.6"
                                stroke-linecap="round" />
                        </svg>
                        {{ $message['content'] }}
                    </span>
                </div>
            @elseif ($message['role'] === 'assistant')
                <div wire:key="msg-{{ $message['id'] }}" class="flex items-start gap-2">
                    <x-ai.avatar size="sm" variant="tint" class="mt-1" />
                    <div class="prose-assistant max-w-[85%] rounded-2xl rounded-bl-md bg-white px-3.5 py-2.5 text-neutral-800 ring-1 ring-black/5 dark:bg-white/[0.06] dark:text-fg dark:ring-white/10"
                        x-html="renderMarkdown(@js($message['content']))"></div>
                </div>
            @else
                <div wire:key="msg-{{ $message['id'] }}" class="flex items-start justify-end gap-2">
                    <div class="flex min-w-0 max-w-[85%] flex-col items-end gap-1">
                        @if (filled($message['author']))
                            <span class="px-1 text-xs text-neutral-500 dark:text-fg-faint">{{ $message['author'] }}</span>
                        @endif
                        <div x-text="@js($message['content'])"
                            class="max-w-full whitespace-pre-wrap rounded-2xl rounded-br-md bg-coollabs/10 px-3.5 py-2.5 text-sm leading-relaxed text-neutral-800 dark:bg-warning/10 dark:text-fg">
                        </div>
                    </div>
                    <x-ai.user-avatar :initial="$message['avatar']['initial']" :url="$message['avatar']['url']"
                        :name="$message['author']" class="mt-1" />
                </div>
            @endif
        @empty
            {{-- Welcome + context-aware suggestions (hidden the moment a message is in flight). --}}
            <div x-show="!streaming && !partial && !thinking && pending.length === 0"
                class="flex flex-1 flex-col items-center justify-center gap-5 px-2 text-center">
                <div class="flex flex-col items-center gap-2">
                    <x-ai.avatar size="lg" variant="tint" />
                    <div class="text-base font-medium text-black dark:text-fg">How can I help?</div>
                    <p class="max-w-[34ch] text-sm text-neutral-600 dark:text-fg-dim">Ask about your servers,
                        deployments, databases, and more.</p>
                </div>
                <div @class([
                    'flex w-full flex-col gap-2',
                    'max-w-md' => $wide,
                ])>
                    <template x-for="suggestion in suggestions" :key="suggestion">
                        <button type="button" x-text="suggestion" x-on:click="sendSuggestion(suggestion)"
                            class="w-full rounded-xl border border-neutral-200 bg-white/60 px-3 py-2.5 text-left text-sm text-neutral-600 dark:text-fg-dim transition-colors hover:border-coollabs/40 hover:text-black dark:hover:text-fg dark:border-white/10 dark:bg-white/[0.03] dark:hover:border-warning/40"></button>
                    </template>
                </div>
            </div>
        @endforelse

        {{-- Optimistic user messages: shown instantly, cleared once the real rows
             load. Kept under wire:ignore so a Livewire re-render mid-turn can't
             wipe the message the user just sent while the agent is thinking. --}}
        <div wire:ignore class="contents">
            <template x-for="(text, index) in pending" :key="'pending-' + index">
                <div class="flex items-start justify-end gap-2">
                    <div
                        class="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-md bg-coollabs/10 px-3.5 py-2.5 text-sm leading-relaxed text-neutral-800 dark:bg-warning/10 dark:text-fg"
                        x-text="text"></div>
                    <template x-if="viewer.url">
                        <img :src="viewer.url" :alt="viewer.initial"
                            class="mt-1 size-6 shrink-0 rounded-full object-cover">
                    </template>
                    <template x-if="! viewer.url">
                        <span
                            class="mt-1 flex size-6 shrink-0 items-center justify-center rounded-full bg-neutral-200 text-[11px] font-semibold text-neutral-700 dark:bg-white/[0.1] dark:text-fg"
                            x-text="viewer.initial"></span>
                    </template>
                </div>
            </template>
        </div>

        {{-- Reasoning / thinking, collapsible like Claude/ChatGPT/T3 Chat. --}}
        <div x-show="reasoning" x-cloak wire:ignore class="flex flex-col gap-1">
            <button type="button" x-on:click="reasoningOpen = !reasoningOpen"
                class="flex w-fit items-center gap-1.5 rounded-md py-0.5 text-xs font-medium text-neutral-500 dark:text-fg-faint transition-colors hover:text-black dark:hover:text-fg">
                <svg x-show="streaming && !partial" class="size-3 shrink-0 animate-spin text-coollabs dark:text-warning"
                    viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
                    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                </svg>
                <span x-text="(streaming && !partial) ? 'Thinking…' : 'Thought process'"></span>
                <svg class="size-3 shrink-0 transition-transform duration-200 ease-[cubic-bezier(0.23,1,0.32,1)] motion-reduce:transition-none"
                    :class="reasoningOpen && 'rotate-90'" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                    <path d="m4.5 3 3 3-3 3" stroke="currentColor" stroke-width="1.25" stroke-linecap="round"
                        stroke-linejoin="round" />
                </svg>
            </button>
            <div x-show="reasoningOpen" x-collapse
                class="ml-1 whitespace-pre-wrap border-l-2 border-black/5 pl-3 text-xs leading-relaxed text-neutral-600 dark:text-fg-dim dark:border-white/10"
                x-text="reasoning"></div>
        </div>

        {{-- Live status before the answer streams: always labelled so a long pause
             reads as "Reading servers…" / "Thinking…" instead of a silent spinner. --}}
        <div x-show="(thinking || activity) && !partial" wire:ignore class="flex items-start gap-2">
            <x-ai.avatar size="sm" variant="tint" class="mt-1" />
            <div class="flex items-center gap-2 rounded-2xl rounded-bl-md bg-white px-3.5 py-2.5 ring-1 ring-black/5 dark:bg-white/[0.06] dark:ring-white/10">
                <svg class="size-3.5 shrink-0 animate-spin text-coollabs dark:text-warning" viewBox="0 0 24 24"
                    fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
                    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                </svg>
                <span class="text-sm text-neutral-600 dark:text-fg-dim"
                    x-text="activity ? activity + '…' : 'Thinking…'"></span>
            </div>
        </div>

        {{-- Live streaming buffer (appended by Echo, seeded from the cached partial) --}}
        <div x-show="streaming || partial" wire:ignore class="flex items-start gap-2">
            <x-ai.avatar size="sm" variant="tint" class="mt-1" />
            <div class="prose-assistant max-w-[85%] rounded-2xl rounded-bl-md bg-white px-3.5 py-2.5 text-neutral-800 ring-1 ring-black/5 dark:bg-white/[0.06] dark:text-fg dark:ring-white/10">
                <span x-html="renderMarkdown(partial)"></span><span x-show="streaming"
                    class="ml-0.5 inline-block h-4 w-1.5 align-middle bg-coollabs animate-pulse dark:bg-warning"></span>
            </div>
        </div>

        {{-- Confirmation cards: the assistant asking to authorize an action. Rendered
             inline in the conversation column (assistant-aligned), not full width. --}}
        @foreach ($this->pendingApprovals as $approval)
            <div wire:key="approval-{{ $approval['id'] }}" x-show="!busy" class="flex items-start gap-2">
                <x-ai.avatar size="sm" variant="tint" class="mt-1" />
                <div
                    class="min-w-0 max-w-[85%] overflow-hidden rounded-2xl rounded-bl-md bg-white ring-1 ring-black/5 dark:bg-white/[0.04] dark:ring-white/10">
                    <div class="px-3.5 py-3">
                        <div class="flex items-center gap-1.5 text-sm font-medium text-black dark:text-fg">
                            @if ($approval['destructive'])
                                <svg class="size-4 shrink-0 text-error" viewBox="0 0 24 24" fill="none"
                                    aria-hidden="true">
                                    <path
                                        d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"
                                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                        stroke-linejoin="round" />
                                </svg>
                            @endif
                            {{ $approval['title'] }}
                        </div>
                        <p class="mt-1 text-[13px] leading-relaxed text-neutral-600 dark:text-fg-dim">
                            {{ $approval['reason'] }}</p>
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-black/5 bg-neutral-50/70 px-3 py-2 dark:border-white/5 dark:bg-white/[0.02]">
                        <x-forms.button wire:click="reject('{{ $approval['id'] }}')"
                            x-on:click="busy = true; thinking = true" wire:loading.attr="disabled">Cancel</x-forms.button>
                        <x-forms.button :isError="$approval['destructive']" :isHighlighted="! $approval['destructive']"
                            wire:click="approve('{{ $approval['id'] }}')" x-on:click="busy = true; thinking = true"
                            wire:loading.attr="disabled">Accept</x-forms.button>
                    </div>
                </div>
            </div>
        @endforeach
        </div>
    </div>

    {{-- Composer: a rounded pill (ChatGPT-style), centered in the readable column on the page. --}}
    <form x-on:submit.prevent="submit()" @class(['mt-2', 'px-4 pb-1' => $wide])>
        <div @class(['mx-auto w-full', 'max-w-3xl' => $wide])>
            <div
                class="flex items-end gap-2 rounded-2xl border border-neutral-200 bg-white px-2.5 py-2 shadow-[var(--shadow-dropdown)] transition-colors focus-within:border-coollabs dark:border-white/10 dark:bg-white/[0.03] dark:focus-within:border-warning">
                <textarea x-ref="composer" x-model="draft" rows="1" x-bind:disabled="busy"
                    x-on:keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); submit(); }"
                    x-on:input="autogrow()" placeholder="Message the assistant"
                    class="min-h-7 max-h-[200px] w-full resize-none border-0 bg-transparent px-1 py-1 text-sm leading-relaxed text-black dark:text-fg placeholder:text-neutral-500 dark:placeholder:text-fg-faint focus:outline-none focus:ring-0"></textarea>
                <template x-if="!busy">
                    <button type="submit" title="Send" x-bind:disabled="!draft.trim()"
                        class="button button-highlighted !size-8 !min-h-8 !px-0 shrink-0 rounded-full transition-transform duration-100 ease-out active:scale-90 disabled:cursor-not-allowed disabled:opacity-40 disabled:active:scale-100">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24"
                            stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5M5 12l7-7 7 7" />
                        </svg>
                    </button>
                </template>
                <template x-if="busy">
                    <button type="button" x-on:click="stopTurn()" title="Stop"
                        class="button !size-8 !min-h-8 !px-0 shrink-0 rounded-full transition-transform duration-100 ease-out active:scale-90">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-3.5" viewBox="0 0 24 24"
                            fill="currentColor">
                            <rect x="6" y="6" width="12" height="12" rx="2" />
                        </svg>
                    </button>
                </template>
            </div>
            @if ($wide)
                <p class="mt-2 text-center text-[11px] text-neutral-500 dark:text-fg-faint">The assistant can make
                    mistakes. It always asks before making changes.</p>
            @endif
        </div>
    </form>

    @script
        <script>
            Alpine.data('assistantThread', ({
                channel,
                initialBusy,
                initialPending,
                viewer
            }) => ({
                viewer: viewer ?? {
                    initial: '?',
                    url: null
                },
                draft: '',
                partial: '',
                reasoning: '',
                reasoningOpen: false,
                activity: '',
                answerStarted: false,
                streaming: false,
                thinking: initialBusy ?? false,
                busy: initialBusy ?? false,
                pending: [],
                lastSeq: 0,
                init() {
                    this.partial = @js($this->partial());
                    this.reasoning = @js($this->reasoning());
                    this.activity = @js($this->activity());
                    // Seed the just-sent first message so it shows immediately after
                    // landing on a freshly-started conversation (no invisible gap).
                    if (initialPending) {
                        this.pending.push(initialPending);
                        this.busy = true;
                        this.thinking = true;
                    }
                    if (! window.Echo) {
                        return;
                    }
                    window.Echo.private(channel)
                        .listen('.assistant.delta', (e) => {
                            this.thinking = false;
                            this.activity = '';
                            this.streaming = true;
                            this.busy = true;
                            // First answer token: collapse the thinking accordion, like Claude/ChatGPT.
                            if (! this.answerStarted) {
                                this.answerStarted = true;
                                this.reasoningOpen = false;
                            }
                            // A missed frame (reconnect/jitter): re-sync to the server's
                            // authoritative cumulative text instead of a stale snapshot,
                            // which is what used to blank the reply mid-stream.
                            const gap = e.sequence > this.lastSeq + 1 && this.lastSeq !== 0;
                            this.lastSeq = e.sequence;
                            if (gap) {
                                this.$wire.partial().then((text) => {
                                    if (text) {
                                        this.partial = text;
                                        this.$nextTick(() => this.scrollToEnd());
                                    }
                                });
                                return;
                            }
                            this.partial += e.delta;
                            this.$nextTick(() => this.scrollToEnd());
                        })
                        .listen('.assistant.reasoning', (e) => {
                            this.reasoning = e.reasoning ?? '';
                            this.thinking = false;
                            this.busy = true;
                            if (! this.answerStarted) {
                                this.reasoningOpen = true;
                            }
                            this.$nextTick(() => this.scrollToEnd());
                        })
                        .listen('.assistant.activity', (e) => {
                            this.activity = e.label ?? '';
                            this.busy = true;
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
                    this.activity = '';
                    this.$wire.stop();
                },
                finish() {
                    this.$wire.$refresh().then(() => {
                        this.pending = [];
                        this.partial = '';
                        this.reasoning = '';
                        this.reasoningOpen = false;
                        this.activity = '';
                        this.answerStarted = false;
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
                    this.reasoning = '';
                    this.reasoningOpen = false;
                    this.activity = '';
                    this.answerStarted = false;
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
                    if (! el) {
                        return;
                    }
                    // Set with `important` so it beats the textarea's `!h-auto`
                    // (height: auto !important), which otherwise wins over a plain
                    // inline height and keeps the field stuck at one line.
                    el.style.setProperty('height', 'auto', 'important');
                    el.style.setProperty('height', Math.min(el.scrollHeight, 200) + 'px', 'important');
                },
                resetHeight() {
                    const el = this.$refs.composer;
                    if (el) {
                        el.style.removeProperty('height');
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
