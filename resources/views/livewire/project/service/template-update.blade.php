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
            {{-- Status and primary actions --}}
            <x-application.settings-section title="Template"
                helper="Review and adopt changes from the latest version of this one-click template.">
                @if ($this->updateAvailable)
                    <x-slot:actions>
                        <x-forms.button wire:click="apply" canGate="update" :canResource="$service" isHighlighted>
                            Apply selected changes
                        </x-forms.button>
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
                            Pick the compose changes to adopt, then apply. Environment variables live in the compose,
                            so their changes appear here too. Redeploy the service afterwards for the changes to take
                            effect.
                        </x-callout>
                    @endif
                </div>
            </x-application.settings-section>

            {{-- Compose changes --}}
            <x-application.settings-section title="Compose changes"
                helper="Select the template changes to merge into your compose. Your other edits stay untouched.">
                <x-slot:actions>
                    <x-forms.button wire:click="replaceAll" canGate="update" :canResource="$service"
                        wire:confirm="Replace your entire compose with the latest template? Your compose customizations will be lost.">
                        Replace with latest
                    </x-forms.button>
                </x-slot:actions>

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
            </x-application.settings-section>
        @endif
    </div>
</div>
