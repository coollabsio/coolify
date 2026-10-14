<div>
    <x-slot:title>
        Team Audit Log | Coolify
    </x-slot>

    <x-team.settings-layout>
        <div class="application-settings-form">
            <x-application.settings-section title="Audit log"
                description="Activity from the last 90 days for the current team." flush>
                <div
                    class="flex flex-col gap-2 border-b border-neutral-200 p-3 sm:flex-row sm:items-center dark:border-white/[0.08]">
                    <div class="relative min-w-0 flex-1 sm:max-w-sm">
                        <x-reicon name="search"
                            class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                        <input wire:model.live.debounce.300ms="search" type="search"
                            placeholder="Search activity" aria-label="Search activity"
                            class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                    </div>
                    <div class="grid grid-cols-2 gap-2 sm:flex">
                        <div class="sm:w-36">
                            <x-forms.listbox id="action" live canGate="viewAdmin" :canResource="currentTeam()"
                                :options="$actionOptions" />
                        </div>
                        <div class="sm:w-36">
                            <x-forms.listbox id="source" live canGate="viewAdmin" :canResource="currentTeam()" :options="[
                                ['value' => 'all', 'label' => 'All sources'],
                                ['value' => 'ui', 'label' => 'Web UI'],
                                ['value' => 'api', 'label' => 'API'],
                                ['value' => 'mcp', 'label' => 'MCP'],
                                ['value' => 'webhook', 'label' => 'Webhook'],
                            ]" />
                        </div>
                    </div>
                </div>

                @if ($events->isNotEmpty())
                    <div class="overflow-x-auto">
                        <div class="data-table transition-opacity" wire:loading.class="opacity-50 pointer-events-none"
                            wire:target="search,action,source,setPage,previousPage,nextPage">
                        <div class="grid min-w-[760px] grid-cols-[14rem_minmax(0,1fr)_12rem_9rem] gap-4 border-b border-neutral-200 px-4 py-2 text-[11px] font-medium uppercase tracking-wide text-neutral-500 dark:border-white/[0.07] dark:text-fg-faint">
                            <span>Actor</span>
                            <span>Activity</span>
                            <span>Source</span>
                            <span class="text-right">Time</span>
                        </div>
                        @foreach ($events as $event)
                            <div wire:key="audit-event-{{ $event->id }}" x-data="{ expanded: false }"
                                class="min-w-[760px] border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                            <div class="grid grid-cols-[14rem_minmax(0,1fr)_12rem_9rem] gap-4 px-4 py-3">
                                <div class="min-w-0">
                                    <div class="truncate text-[12px] font-medium text-black dark:text-fg">
                                        {{ $event->actor_name ?: Str::headline($event->actor_type) }}
                                    </div>
                                    @if ($event->actor_email)
                                        <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                            {{ $event->actor_email }}
                                        </div>
                                    @endif
                                    @if ($event->actor_token_name)
                                        <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint"
                                            title="Token: {{ $event->actor_token_name }}">
                                            Token: {{ $event->actor_token_name }}
                                        </div>
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <div class="truncate text-[13px] font-medium text-black dark:text-fg">
                                        {{ $event->description }}
                                    </div>
                                    <div class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                        {{ $event->event }}
                                    </div>
                                    @if (filled($event->changes))
                                        <button type="button" @click="expanded = ! expanded"
                                            class="mt-1 text-[11px] font-medium text-accent hover:underline"
                                            :aria-expanded="expanded">
                                            <span x-text="expanded ? 'Hide changes' : 'View changes'">View changes</span>
                                        </button>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 text-[12px] text-neutral-500 dark:text-fg-dim">
                                    <span class="rounded-md bg-neutral-100 px-2 py-1 dark:bg-white/[0.06]">
                                        {{ Str::upper($event->source) }}
                                    </span>
                                    <span>{{ Str::headline($event->action) }}</span>
                                </div>
                                <time datetime="{{ $event->created_at->toIso8601String() }}"
                                    title="{{ $event->created_at->toDayDateTimeString() }}"
                                    class="self-center text-right text-[11px] text-neutral-500 dark:text-fg-faint">
                                    {{ $event->created_at->diffForHumans() }}
                                </time>
                            </div>
                            @if (filled($event->changes))
                                <div x-cloak x-show="expanded" x-collapse
                                    class="border-t border-neutral-200 bg-neutral-50 px-4 py-3 dark:border-white/[0.07] dark:bg-white/[0.02]">
                                    <div class="grid grid-cols-[12rem_minmax(0,1fr)_minmax(0,1fr)] gap-4 pb-2 text-[10px] font-medium uppercase tracking-wide text-neutral-500 dark:text-fg-faint">
                                        <span>Field</span>
                                        <span>Previous value</span>
                                        <span>New value</span>
                                    </div>
                                    @foreach ($event->changes as $field => $change)
                                        <div class="grid grid-cols-[12rem_minmax(0,1fr)_minmax(0,1fr)] gap-4 border-t border-neutral-200 py-2 text-[12px] dark:border-white/[0.07]">
                                            <span class="font-medium text-black dark:text-fg">{{ Str::headline($field) }}</span>
                                            <span class="break-words whitespace-pre-wrap text-neutral-600 dark:text-fg-dim">{{ is_array($change['old']) ? json_encode($change['old'], JSON_UNESCAPED_SLASHES) : (is_bool($change['old']) ? ($change['old'] ? 'True' : 'False') : ($change['old'] ?? 'None')) }}</span>
                                            <span class="break-words whitespace-pre-wrap text-neutral-600 dark:text-fg-dim">{{ is_array($change['new']) ? json_encode($change['new'], JSON_UNESCAPED_SLASHES) : (is_bool($change['new']) ? ($change['new'] ? 'True' : 'False') : ($change['new'] ?? 'None')) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                            </div>
                        @endforeach
                    </div>
                    </div>

                    <x-table-pagination :from="$events->firstItem() ?? 0" :to="$events->lastItem() ?? 0"
                        :total="$events->total()" :current-page="$events->currentPage()"
                        :last-page="$events->lastPage()" wire-target="setPage,previousPage,nextPage"
                        previous-action="previousPage" next-action="nextPage">
                        <x-slot:pageSize>
                            <x-page-size-select model="perPage" livewire storage-key="coolify.page-size.audit-log"
                                canGate="viewAdmin" :canResource="currentTeam()" />
                        </x-slot:pageSize>
                    </x-table-pagination>
                @else
                    <x-empty title="No activity found"
                        description="Team actions will appear here as they happen." icon-name="time-back" size="sm" />
                @endif
            </x-application.settings-section>
        </div>
    </x-team.settings-layout>
</div>
