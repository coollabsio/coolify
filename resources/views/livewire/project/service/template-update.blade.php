<div>
    <div class="application-settings-workspace flex flex-col gap-6">
        @if ($this->template === null)
            <x-application.settings-section title="Template"
                helper="Review and adopt changes from the latest version of this one-click template.">
                <x-empty size="sm" title="No template linked"
                    description="This service is not linked to a known one-click template, so there is nothing to compare.">
                    <x-slot:icon>
                        <x-reicon name="layers" class="size-8" />
                    </x-slot:icon>
                </x-empty>
            </x-application.settings-section>
        @else
            {{-- Status and dismiss --}}
            <x-application.settings-section title="Template"
                helper="Review and adopt changes from the latest version of this one-click template.">
                @if ($this->updateAvailable)
                    <x-slot:actions>
                        <x-forms.button wire:click="dismiss" canGate="update" :canResource="$service">
                            Dismiss update
                        </x-forms.button>
                    </x-slot:actions>
                @endif

                <div class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($this->updateAvailable)
                            <x-status-badge label="Update available" type="warning" />
                        @else
                            <x-status-badge label="Up to date" type="success" />
                        @endif
                        @if ($this->latestUpdatedAt)
                            <span class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                Latest template version: {{ $this->latestUpdatedAt }}
                            </span>
                        @endif
                    </div>

                    @if ($this->updateAvailable)
                        <x-callout type="info" title="A newer template version is available">
                            Cherry-pick the changes in review mode, or switch to edit mode to adjust the compose by
                            hand. Redeploy the service afterwards for the changes to take effect.
                        </x-callout>
                    @endif
                </div>
            </x-application.settings-section>

            {{-- Compose: review (diff) or edit (inline editor) --}}
            <x-application.settings-section title="Compose"
                helper="Review the template's changes hunk by hunk, or edit the compose directly. Environment variables live in the compose, so their changes appear here too.">
                <x-slot:actions>
                    <div class="flex h-9 items-center rounded-lg border border-neutral-200 bg-white p-0.5 dark:border-white/[0.08] dark:bg-white/[0.06]"
                        role="tablist" aria-label="Compose mode">
                        <button type="button" wire:click="setMode('review')" role="tab"
                            aria-selected="{{ $mode === 'review' ? 'true' : 'false' }}" @class([
                                'rounded-md px-2.5 py-1 text-[12px] font-medium transition-colors',
                                'control-selected' => $mode === 'review',
                                'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg' => $mode !== 'review',
                            ])>
                            Review changes
                        </button>
                        <button type="button" wire:click="setMode('edit')" role="tab"
                            aria-selected="{{ $mode === 'edit' ? 'true' : 'false' }}" @class([
                                'rounded-md px-2.5 py-1 text-[12px] font-medium transition-colors',
                                'control-selected' => $mode === 'edit',
                                'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg' => $mode !== 'edit',
                            ])>
                            Edit compose
                        </button>
                    </div>
                </x-slot:actions>

                @if ($mode === 'review')
                    @forelse ($this->hunks as $hunk)
                        <div @class([
                            'overflow-hidden rounded-lg border border-neutral-200 dark:border-white/[0.08]',
                            'mt-3' => !$loop->first,
                        ])>
                            <div class="border-b border-neutral-200 dark:border-white/[0.08]">
                                <x-forms.checkbox :id="'acceptedHunks.' . $hunk['index']"
                                    label="Change {{ $hunk['index'] + 1 }}" fullWidth />
                            </div>
                            <pre class="overflow-x-auto p-3 font-mono text-xs leading-5">@foreach ($hunk['lines'] as $line)<div @class([
    'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => $line['type'] === 'add',
    'bg-red-500/10 text-red-700 dark:text-red-300' => $line['type'] === 'remove',
    'text-neutral-500 dark:text-fg-dim' => $line['type'] === 'context',
])><span class="select-none">{{ $line['type'] === 'add' ? '+' : ($line['type'] === 'remove' ? '-' : ' ') }} </span>{{ $line['text'] }}</div>@endforeach</pre>
                        </div>
                    @empty
                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                            Your compose already matches the latest template.
                        </p>
                    @endforelse

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <x-forms.button wire:click="apply" canGate="update" :canResource="$service" isHighlighted>
                            Apply selected changes
                        </x-forms.button>
                        <x-forms.button wire:click="replaceAll" canGate="update" :canResource="$service"
                            wire:confirm="Replace your entire compose with the latest template? Your compose customizations will be lost.">
                            Replace with latest
                        </x-forms.button>
                    </div>
                @else
                    <div class="flex flex-col gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-forms.button wire:click="seedFromLatest" canGate="update" :canResource="$service">
                                Load latest template
                            </x-forms.button>
                            <x-forms.button wire:click="seedFromCurrent" canGate="update" :canResource="$service">
                                Load my current compose
                            </x-forms.button>
                        </div>

                        <div class="compose-editor-container min-h-[24rem] overflow-hidden rounded-lg border border-neutral-200 bg-white dark:border-white/[0.10] dark:bg-[#0b0b0c]"
                            style="--editor-height: clamp(24rem, calc(100dvh - 25rem), 48rem)">
                            <x-forms.textarea allowTab useMonacoEditor monacoEditorLanguage="yaml"
                                id="editorContent" />
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <x-forms.button wire:click="applyEditor" canGate="update" :canResource="$service"
                                isHighlighted>
                                Apply compose
                            </x-forms.button>
                            <span class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                Saved as your compose after a YAML check. Redeploy for it to take effect.
                            </span>
                        </div>
                    </div>
                @endif
            </x-application.settings-section>
        @endif
    </div>
</div>
