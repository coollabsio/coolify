<div>
    @if ($title)
    <x-modal-input :title="$title" :subtitle="$currentSummary ? 'Current access: '.$currentSummary : 'The member gets these scopes when they accept the invitation.'" wireOpen="open"
        :wireIgnore="false" :closeOutside="false">
        <x-slot:content><span></span></x-slot:content>

        @php
            $presetInfo = [
                'viewer' => ['label' => 'Viewer', 'description' => 'Read the whole team.'],
                'developer' => ['label' => 'Developer', 'description' => 'Write and deploy in one project.'],
                'operator' => ['label' => 'Operator', 'description' => 'Write, deploy, and delete in the whole team.'],
                'full' => ['label' => 'Full access', 'description' => 'Every level in the whole team.'],
            ];
            $presetCard = 'flex w-full flex-col items-start gap-0.5 rounded-lg border px-3 py-2 text-left transition-colors';
            $presetIdle = 'border-neutral-200 hover:bg-neutral-100/80 dark:border-white/[0.08] dark:hover:bg-white/[0.035]';
            $presetActive = 'border-coollabs bg-coollabs/5 dark:border-brand-light/60 dark:bg-brand/10';
        @endphp

        {{-- The rows live in Alpine. They reach the server only with Save, because the entangle is not live. --}}
        <div class="flex flex-col gap-5 lg:w-3xl" x-data="{
            rows: $wire.entangle('grants'),
            levels: @js($levels),
            presets: @js($presets),
            row(targets, levels) {
                return {
                    key: Math.random().toString(36).slice(2, 12),
                    targets,
                    levels: Object.fromEntries(this.levels.map((level) => [level.name, levels.includes(level.name)])),
                };
            },
            checked(row) {
                return this.levels.filter((level) => row.levels[level.name]).map((level) => level.name).sort();
            },
            applyPreset(name, projectUuid = null) {
                const preset = this.presets[name];
                const targets = preset.targets.map((target) => target.replace('__project__', projectUuid));
                this.rows = [this.row(targets, this.checked(preset))];
            },
            addRow() {
                this.rows = [...this.rows, this.row([], [])];
            },
            removeRow(key) {
                this.rows = this.rows.filter((row) => row.key !== key);
            },
            includedBy(row, name) {
                const parent = this.levels
                    .filter((level) => row.levels[level.name])
                    .sort((a, b) => b.includes.length - a.includes.length)
                    .find((level) => level.name !== name && level.includes.includes(name));
                return parent?.label ?? null;
            },
            get activePreset() {
                if (this.rows.length !== 1 || this.rows[0].targets.length !== 1) return null;
                const target = String(this.rows[0].targets[0]);
                const levels = this.checked(this.rows[0]).join(',');
                return Object.keys(this.presets).find((name) => {
                    const preset = this.presets[name];
                    const presetTarget = preset.targets[0];
                    const sameTarget = presetTarget.startsWith('project:') ? target.startsWith('project:') : target === presetTarget;
                    return sameTarget && this.checked(preset).join(',') === levels;
                }) ?? null;
            },
        }">
            <section class="flex flex-col gap-2">
                <div>
                    <h4 class="text-[13px] font-medium text-neutral-800 dark:text-fg">Start from a preset</h4>
                    <p class="text-[12px] text-neutral-500 dark:text-fg-faint">A preset replaces the scopes below.
                        You can change them after.</p>
                </div>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach ($presetInfo as $preset => $info)
                        @if ($preset === 'developer')
                            @if ($projectOptions->isNotEmpty())
                                <div x-data="{ open: false }" @keydown.escape.window="open = false">
                                    <button type="button" class="{{ $presetCard }}" x-ref="trigger"
                                        :class="activePreset === 'developer' ? @js($presetActive) : @js($presetIdle)"
                                        @click="open = !open" aria-haspopup="menu" :aria-expanded="open">
                                        <span class="flex w-full items-center justify-between gap-2 text-[13px] text-neutral-800 dark:text-fg">
                                            {{ $info['label'] }}
                                            <x-reicon name="chevron-down" class="size-3 text-neutral-500 dark:text-fg-faint" />
                                        </span>
                                        <span class="text-[12px] text-neutral-500 dark:text-fg-faint">{{ $info['description'] }}</span>
                                    </button>
                                    <template x-teleport="body">
                                        <div x-show="open" x-cloak role="menu"
                                            x-anchor.bottom-start.fixed.offset.4="$refs.trigger"
                                            @click.outside="if (! $refs.trigger.contains($event.target)) open = false"
                                            class="listbox-panel min-w-48! z-[100]!">
                                            @foreach ($projectOptions as $project)
                                                <button type="button" class="listbox-option justify-start!"
                                                    @click="applyPreset('developer', @js(Str::after($project['value'], 'project:'))); open = false">
                                                    {{ $project['label'] }}
                                                </button>
                                            @endforeach
                                        </div>
                                    </template>
                                </div>
                            @endif
                        @else
                            <button type="button" class="{{ $presetCard }}"
                                :class="activePreset === @js($preset) ? @js($presetActive) : @js($presetIdle)"
                                @click="applyPreset(@js($preset))">
                                <span class="text-[13px] text-neutral-800 dark:text-fg">{{ $info['label'] }}</span>
                                <span class="text-[12px] text-neutral-500 dark:text-fg-faint">{{ $info['description'] }}</span>
                            </button>
                        @endif
                    @endforeach
                </div>
            </section>

            <section class="flex flex-col gap-2">
                <div>
                    <h4 class="text-[13px] font-medium text-neutral-800 dark:text-fg">Scopes</h4>
                    <p class="text-[12px] text-neutral-500 dark:text-fg-faint">Each scope gives levels on the whole
                        team, projects, environments, or resources. Scopes add up.</p>
                </div>

                <div x-show="rows.length === 0" x-cloak>
                    <x-callout type="warning" title="No access">
                        {{ $forInvitation ? 'The invited member' : 'This member' }} will not see any project, environment, or resource in this team.
                    </x-callout>
                </div>

                @if ($errors->any())
                    <x-callout type="danger" title="Check the scopes">
                        @foreach ($errors->getMessages() as $key => $messages)
                            <p>Scope {{ ((int) explode('.', $key)[1]) + 1 }}: {{ $messages[0] }}</p>
                        @endforeach
                    </x-callout>
                @endif

                <template x-for="row in rows" :key="row.key">
                    <div class="flex flex-col gap-3 rounded-lg border border-neutral-200 p-3 dark:border-white/[0.08]">
                        <div class="flex items-end gap-2">
                            <div class="min-w-0 flex-1">
                                <x-forms.listbox htmlId="member-access-targets" label="Applies to" :wire="false" :value="[]"
                                    x-model="row.targets" :options="$this->scopeOptions" placeholder="Choose scopes"
                                    multiple searchable portal countLabel="resource" />
                            </div>
                            <button type="button" class="icon-button mb-1 shrink-0" title="Remove scope"
                                aria-label="Remove this scope" @click="removeRow(row.key)">
                                <x-reicon name="trash" class="size-3.5" />
                            </button>
                        </div>

                        <div class="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                            <template x-for="level in levels" :key="level.name">
                                <label class="group flex items-start gap-2.5 rounded-md px-2.5 py-2 transition-colors"
                                    :class="includedBy(row, level.name) ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:bg-neutral-100/80 dark:hover:bg-white/[0.035]'">
                                    <span class="relative mt-0.5 flex size-[18px] shrink-0">
                                        <input type="checkbox"
                                            :checked="includedBy(row, level.name) !== null || row.levels[level.name]"
                                            @change="row.levels[level.name] = $event.target.checked"
                                            :disabled="includedBy(row, level.name) !== null"
                                            class="peer absolute inset-0 z-10 m-0 h-full w-full cursor-pointer appearance-none opacity-0 disabled:cursor-not-allowed" />
                                        <span
                                            class="pointer-events-none absolute inset-0 rounded-[5px] border border-neutral-300 bg-white transition-colors peer-checked:border-coollabs peer-checked:bg-coollabs peer-focus-visible:ring-2 peer-focus-visible:ring-coollabs/25 dark:border-white/[0.14] dark:bg-white/[0.045] dark:peer-checked:border-brand dark:peer-checked:bg-brand"></span>
                                        <svg class="pointer-events-none absolute inset-0 m-auto size-3 text-white opacity-0 peer-checked:opacity-100"
                                            viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                            <path d="m2.25 6.15 2.35 2.3 5.15-5" stroke="currentColor" stroke-width="1.8"
                                                stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </span>
                                    <span class="flex min-w-0 flex-col">
                                        <span class="text-[13px] text-neutral-800 dark:text-fg" x-text="level.label"></span>
                                        <span class="text-[12px] text-neutral-500 dark:text-fg-faint"
                                            x-text="includedBy(row, level.name) ? `Included in ${includedBy(row, level.name)}.` : level.description"></span>
                                    </span>
                                </label>
                            </template>
                        </div>
                    </div>
                </template>

                <div>
                    <button type="button" class="button h-7! px-2.5! text-[11px]!" @click="addRow()">
                        <x-reicon name="plus" class="size-3" />
                        Add scope
                    </button>
                </div>
            </section>
        </div>

        <x-slot:footer>
            <x-forms.button @click="modalOpen = false">Cancel</x-forms.button>
            <x-forms.button isHighlighted wire:click="save" canGate="manageMembers"
                :canResource="$team">Save</x-forms.button>
        </x-slot:footer>
    </x-modal-input>
    @endif
</div>
