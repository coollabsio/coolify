<div @if ($finish && $openCount > 0) wire:init="refreshSteps" wire:poll.15s="refreshSteps" @endif>
    @if ($finish)
        @php
            $doneCount = $totalCount - $openCount;
            $lastCheckedAt = data_get($finish, 'last_checked_at');
            $badges = [
                'action' => ['Needs action', 'warning'],
                'unchecked' => ['Cannot check', 'neutral'],
                'pending' => ['Not checked yet', 'neutral'],
            ];
        @endphp
        <div class="mb-4 rounded-lg p-4 ring-1 ring-neutral-200 dark:ring-white/[0.08]" data-testid="transfer-finish">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-neutral-950 dark:text-fg">Finish the transfer</h3>
                    <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                        {{ $doneCount }} of {{ $totalCount }} done.
                        @if ($openCount > 0)
                            Coolify checks the open steps every 30 seconds.
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    @if ($openCount > 0)
                        <span class="text-xs text-neutral-500 dark:text-fg-dim">
                            {{ $lastCheckedAt ? 'Checked '.\Illuminate\Support\Carbon::parse($lastCheckedAt)->format('H:i') : 'Checking…' }}
                        </span>
                        @can('update', $server)
                            <button type="button" class="icon-button" title="Check now" wire:click="checkAllNow"
                                wire:loading.attr="disabled" wire:target="checkAllNow">
                                <x-reicon name="refresh" class="size-4" />
                            </button>
                        @endcan
                    @else
                        <x-forms.button isHighlighted wire:click="finishTransfer" canGate="update" :canResource="$server">
                            Finish the transfer
                        </x-forms.button>
                    @endif
                </div>
            </div>
            <div class="mt-3 h-1 overflow-hidden rounded-full bg-neutral-200 dark:bg-white/[0.08]">
                <div class="h-full rounded-full bg-success transition-all"
                    style="width: {{ $totalCount > 0 ? round($doneCount / $totalCount * 100) : 0 }}%"></div>
            </div>

            @foreach ($openGroups as $group)
                <div class="mt-4" wire:key="transfer-group-{{ $loop->index }}-{{ $group['label'] }}">
                    <p class="text-xs font-medium text-neutral-500 dark:text-fg-dim">{{ $group['label'] }}</p>
                    <ul class="mt-1 flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.06]">
                        @foreach ($group['items'] as $step)
                            @php
                                $fixLabel = $checklist->fixLabel($step);
                                $manualOnly = ! $checklist->canCheck($step);
                                $hasDetails = filled($step['hint'] ?? null) || count($step['details'] ?? []) > 0 || filled($step['error'] ?? null);
                                [$badgeLabel, $badgeType] = $badges[$step['status']] ?? ['Not checked yet', 'neutral'];
                            @endphp
                            <li wire:key="transfer-step-{{ $step['key'] }}" class="py-2" x-data="{ details: false, menu: false }">
                                <div class="flex items-center gap-3">
                                    <x-status-badge :label="$badgeLabel" :type="$badgeType" />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm text-neutral-950 dark:text-fg">{{ $step['label'] }}</p>
                                        <p class="truncate text-xs text-neutral-500 dark:text-fg-dim">
                                            {{ $step['message'] ?? $step['hint'] }}
                                            @if ($hasDetails)
                                                <button type="button" class="ml-1 underline" @click="details = !details"
                                                    x-text="details ? 'Hide details' : 'Details'"></button>
                                            @endif
                                        </p>
                                    </div>
                                    @can('update', $server)
                                        <div class="flex shrink-0 items-center gap-1">
                                            @if ($fixLabel)
                                                <x-forms.button wire:click="fixStep('{{ $step['key'] }}')">{{ $fixLabel }}</x-forms.button>
                                            @elseif ($step['link'] ?? null)
                                                <a href="{{ $step['link'] }}" {{ wireNavigate() }} class="button">{{ $step['link_label'] ?? 'Open' }}</a>
                                            @elseif ($manualOnly)
                                                <x-forms.button wire:click="markStepDone('{{ $step['key'] }}')">Mark as done</x-forms.button>
                                            @endif
                                            <div class="relative" @click.outside="menu = false" @keydown.escape.window="menu = false">
                                                <button type="button" class="icon-button" title="More actions" aria-haspopup="menu"
                                                    :aria-expanded="menu" @click="menu = !menu">
                                                    <x-reicon name="dots-horizontal" class="size-4" />
                                                </button>
                                                <div x-cloak x-show="menu" x-transition.origin.top.right role="menu"
                                                    class="listbox-panel top-full! right-0! left-auto! z-[90]! mt-1! w-40! min-w-40!">
                                                    @unless ($manualOnly)
                                                        <button type="button" class="listbox-option justify-start!" role="menuitem"
                                                            wire:click="checkStep('{{ $step['key'] }}')" @click="menu = false">Check now</button>
                                                        <button type="button" class="listbox-option justify-start!" role="menuitem"
                                                            wire:click="markStepDone('{{ $step['key'] }}')" @click="menu = false">Mark as done</button>
                                                    @endunless
                                                    @if ($fixLabel && ($step['link'] ?? null))
                                                        <a href="{{ $step['link'] }}" {{ wireNavigate() }} class="listbox-option justify-start!"
                                                            role="menuitem">{{ $step['link_label'] ?? 'Open' }}</a>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endcan
                                </div>
                                @if ($hasDetails)
                                    <div x-cloak x-show="details" class="mt-2 ml-1 flex flex-col gap-1.5 text-xs text-neutral-600 dark:text-fg-dim">
                                        @if (filled($step['hint'] ?? null) && filled($step['message'] ?? null))
                                            <p>{{ $step['hint'] }}</p>
                                        @endif
                                        @foreach ($step['details'] ?? [] as $detail)
                                            <div class="flex min-w-0 items-center gap-2" wire:key="transfer-detail-{{ $step['key'] }}-{{ $detail['label'] }}">
                                                <span class="shrink-0">{{ $detail['label'] }}</span>
                                                <code class="min-w-0 truncate rounded bg-neutral-100 px-1.5 py-0.5 dark:bg-white/[0.06]">{{ $detail['value'] }}</code>
                                                <x-copy-button :value="$detail['value']" />
                                            </div>
                                        @endforeach
                                        @if (filled($step['error'] ?? null))
                                            <p class="break-words">{{ $step['error'] }}</p>
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            @if ($doneCount > 0)
                <div class="mt-4" x-data="{ showDone: false }">
                    <button type="button" class="flex items-center gap-1 text-xs text-neutral-500 dark:text-fg-dim"
                        @click="showDone = !showDone">
                        <x-reicon name="chevron-down" class="size-3 transition-transform" ::class="showDone ? '' : '-rotate-90'" />
                        {{ $doneCount }} {{ $doneCount === 1 ? 'step is' : 'steps are' }} OK or done
                    </button>
                    <ul x-cloak x-show="showDone" class="mt-1 flex flex-col gap-1">
                        @foreach ($doneItems as $step)
                            <li wire:key="transfer-done-{{ $step['key'] }}" class="flex items-center gap-2 text-xs text-neutral-500 dark:text-fg-dim">
                                <x-reicon name="check-circle" class="size-3.5 text-success" />
                                <span class="text-neutral-700 dark:text-fg">{{ $step['label'] }}</span>
                                <span class="truncate">{{ $step['message'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($openCount > 0)
                @can('update', $server)
                    <div class="mt-4 flex justify-end">
                        <x-modal-confirmation title="Finish with open steps?" submitAction="finishTransfer"
                            :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Finish the transfer"
                            :warningMessage="'<strong>'.$openCount.' steps are not OK or done.</strong> Coolify does not check them again. Webhooks, DNS records, or runners can still point to the other instance.'"
                            :actions="collect($openGroups)->flatMap(fn ($group) => $group['items'])->map(fn ($step) => 'Skipped: '.$step['label'])->all()">
                            <x-slot:trigger>
                                <button type="button" class="text-xs text-neutral-500 underline dark:text-fg-dim">Finish with open steps…</button>
                            </x-slot:trigger>
                        </x-modal-confirmation>
                    </div>
                @endcan
            @endif
        </div>
    @endif
</div>
