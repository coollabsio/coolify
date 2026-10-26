<div>
    @if ($member)
    <x-modal-input title="Access for {{ $member->name }}" :subtitle="'Now: '.$currentSummary" wireOpen="open"
        :wireIgnore="false" :closeOutside="false">
        <x-slot:content><span></span></x-slot:content>

        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-[12px] text-neutral-500 dark:text-fg-dim">Presets</span>
                <button type="button" class="button h-7! px-2.5! text-[11px]!" wire:click="applyPreset('viewer')">
                    Viewer
                </button>
                @if ($projectOptions->isNotEmpty())
                    <div x-data="{ open: false }" @keydown.escape.window="open = false">
                        <button type="button" class="button h-7! px-2.5! text-[11px]!" x-ref="trigger"
                            @click="open = !open" aria-haspopup="menu" :aria-expanded="open">
                            Developer
                            <x-reicon name="chevron-down" class="size-3" />
                        </button>
                        <template x-teleport="body">
                            <div x-show="open" x-cloak role="menu" x-anchor.bottom-start.fixed.offset.4="$refs.trigger"
                                @click.outside="if (! $refs.trigger.contains($event.target)) open = false"
                                class="listbox-panel min-w-48! z-[100]!">
                                @foreach ($projectOptions as $project)
                                    <button type="button" class="listbox-option justify-start!"
                                        wire:click="applyPreset('developer', '{{ Str::after($project['value'], 'project:') }}')"
                                        @click="open = false">
                                        {{ $project['label'] }}
                                    </button>
                                @endforeach
                            </div>
                        </template>
                    </div>
                @endif
                <button type="button" class="button h-7! px-2.5! text-[11px]!" wire:click="applyPreset('operator')">
                    Operator
                </button>
                <button type="button" class="button h-7! px-2.5! text-[11px]!" wire:click="applyPreset('full')">
                    Full access
                </button>
            </div>

            @if (count($grants) === 0)
                <x-callout type="warning" title="No access">
                    This member will not see any project, environment, or resource in this team.
                </x-callout>
            @endif

            @foreach ($grants as $index => $grant)
                <div wire:key="member-access-grant-{{ $grant['key'] }}"
                    class="flex flex-col gap-2 border-b border-neutral-200 pb-4 last:border-b-0 last:pb-0 dark:border-white/[0.08]">
                    <div class="flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <x-forms.listbox id="grants.{{ $index }}.target" :options="$this->scopeOptions"
                                placeholder="Choose a scope" searchable portal />
                        </div>
                        <button type="button" class="icon-button mt-1 shrink-0" title="Remove"
                            aria-label="Remove this grant" wire:click="removeGrant('{{ $grant['key'] }}')">
                            <x-reicon name="trash" class="size-3.5" />
                        </button>
                    </div>
                    <div class="flex flex-wrap gap-x-1">
                        @foreach ($levels as $level)
                            <x-forms.checkbox id="grants.{{ $index }}.levels.{{ $level->name }}"
                                :label="$level->label()" />
                        @endforeach
                    </div>
                    @error("grants.{$index}.levels")
                        <p class="text-[12px] text-error">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach

            <div>
                <button type="button" class="button h-7! px-2.5! text-[11px]!" wire:click="addGrant">
                    <x-reicon name="plus" class="size-3" />
                    Add grant
                </button>
            </div>
        </div>

        <x-slot:footer>
            <x-forms.button @click="modalOpen = false">Cancel</x-forms.button>
            <x-forms.button isHighlighted wire:click="save">Save</x-forms.button>
        </x-slot:footer>
    </x-modal-input>
    @endif
</div>
