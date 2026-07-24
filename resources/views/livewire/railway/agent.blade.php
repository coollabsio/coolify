<div>
    <div x-data="{ open: @entangle('open') }" x-cloak>
        {{-- Slide-over panel --}}
        <div x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
            class="fixed top-0 right-0 z-[60] flex h-full w-full max-w-[440px] flex-col shadow-2xl"
            style="background: var(--color-rw-surface); border-left: 1px solid var(--color-rw-border);">

            {{-- Header --}}
            <div class="flex items-center justify-between px-4 py-3 shrink-0"
                style="border-bottom: 1px solid var(--color-rw-border);">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-md"
                        style="background: var(--color-rw-elevated); border: 1px solid var(--color-rw-border);">
                        <svg class="w-3.5 h-3.5 text-rw-text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2M20 14h2M15 13v2M9 13v2"/></svg>
                    </span>
                    <div class="text-[13px] font-semibold text-rw-text">Assistant</div>
                    <div class="text-[11px] text-rw-subtle">{{ $environment->name }}</div>
                </div>
                <button type="button" @click="open = false" class="rw-icon-btn hover:rw-icon-btn-hover">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            @unless ($configured)
                {{-- Not configured --}}
                <div class="flex-1 flex flex-col items-center justify-center gap-3 px-6 text-center">
                    <div class="text-[13px] font-semibold text-rw-text">Assistant not configured</div>
                    <div class="text-[12px] text-rw-muted leading-relaxed">
                        Set <code class="px-1 rounded" style="background: var(--color-rw-elevated);">ANTHROPIC_API_KEY</code>
                        in your <code class="px-1 rounded" style="background: var(--color-rw-elevated);">.env</code>
                        and restart the app to enable the assistant.
                    </div>
                </div>
            @else
                {{-- Transcript --}}
                <div class="flex-1 overflow-y-auto px-4 py-4 space-y-3" id="rw-agent-scroll">
                    @forelse ($transcript as $m)
                        @if ($m['role'] === 'user')
                            <div class="flex justify-end">
                                <div class="max-w-[85%] rounded-lg px-3 py-2 text-[13px] text-rw-text"
                                    style="background: var(--color-coollabs, #6b16ed);">{{ $m['text'] }}</div>
                            </div>
                        @elseif ($m['role'] === 'assistant')
                            <div class="flex justify-start">
                                <div class="max-w-[90%] rounded-lg px-3 py-2 text-[13px] text-rw-text whitespace-pre-wrap"
                                    style="background: var(--color-rw-elevated); border: 1px solid var(--color-rw-border);">{{ $m['text'] }}</div>
                            </div>
                        @else
                            <div class="flex justify-center">
                                <div class="text-[11px] text-rw-subtle">{{ $m['text'] }}</div>
                            </div>
                        @endif
                    @empty
                        <div class="text-[12px] text-rw-muted leading-relaxed">
                            Ask about this environment — e.g. <span class="text-rw-text">"what's running?"</span>,
                            <span class="text-rw-text">"why did the last deploy fail?"</span>, or
                            <span class="text-rw-text">"set NODE_ENV=production on the API and redeploy"</span>.
                        </div>
                    @endforelse

                    {{-- Pending confirmation cards --}}
                    @foreach ($pending as $p)
                        <div class="rounded-lg p-3 space-y-2"
                            style="background: var(--color-rw-elevated); border: 1px solid var(--color-warning, #f59e0b);">
                            <div class="flex items-center gap-2">
                                <svg class="w-3.5 h-3.5" style="color: var(--color-warning, #f59e0b);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/></svg>
                                <div class="text-[12px] font-semibold text-rw-text">Confirm action</div>
                            </div>
                            <div class="text-[13px] text-rw-text">{{ $p['summary'] }}</div>
                            @if (($p['name'] ?? '') === 'set_env_var')
                                <div class="text-[11px] text-rw-subtle font-mono truncate">
                                    {{ data_get($p, 'input.key') }} = {{ \Illuminate\Support\Str::limit((string) data_get($p, 'input.value'), 40) }}
                                </div>
                            @endif
                        </div>
                    @endforeach

                    @if ($pending !== [])
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="approve" wire:loading.attr="disabled"
                                class="rw-btn-primary hover:rw-btn-primary-hover">Approve &amp; run</button>
                            <button type="button" wire:click="deny" wire:loading.attr="disabled"
                                class="rw-btn hover:rw-btn-hover">Decline</button>
                        </div>
                    @endif

                    @if ($error)
                        <div class="rounded-lg px-3 py-2 text-[12px]"
                            style="background: color-mix(in srgb, #ef4444 12%, transparent); color: #f87171; border: 1px solid color-mix(in srgb, #ef4444 30%, transparent);">
                            {{ $error }}
                        </div>
                    @endif

                    {{-- Working indicator --}}
                    <div wire:loading wire:target="send,approve,deny" class="flex items-center gap-2 text-[12px] text-rw-muted">
                        <svg class="w-3.5 h-3.5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                        Thinking…
                    </div>
                </div>

                {{-- Composer --}}
                <div class="px-3 py-3 shrink-0" style="border-top: 1px solid var(--color-rw-border);">
                    <div class="flex items-end gap-2">
                        <input type="text" wire:model="input" wire:keydown.enter.prevent="send"
                            @disabled($pending !== [])
                            placeholder="{{ $pending !== [] ? 'Approve or decline the action above…' : 'Ask or instruct…' }}"
                            class="flex-1 rounded-md px-3 py-2 text-[13px] text-rw-text bg-transparent focus:outline-none"
                            style="background: var(--color-rw-elevated); border: 1px solid var(--color-rw-border);" />
                        <button type="button" wire:click="send" wire:loading.attr="disabled" @disabled($pending !== [])
                            class="rw-icon-btn hover:rw-icon-btn-hover border shrink-0"
                            style="border-color: var(--color-rw-border); background: var(--color-rw-elevated);" title="Send">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7Z"/></svg>
                        </button>
                    </div>
                </div>
            @endunless
        </div>
    </div>
</div>
