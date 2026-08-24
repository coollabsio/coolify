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
                            <x-forms.listbox id="action" live canGate="viewAdmin" :canResource="currentTeam()" :options="[
                                ['value' => 'all', 'label' => 'All actions'],
                                ['value' => 'created', 'label' => 'Created'],
                                ['value' => 'updated', 'label' => 'Updated'],
                                ['value' => 'deleted', 'label' => 'Deleted'],
                                ['value' => 'deployed', 'label' => 'Deployed'],
                                ['value' => 'started', 'label' => 'Started'],
                                ['value' => 'stopped', 'label' => 'Stopped'],
                                ['value' => 'restarted', 'label' => 'Restarted'],
                                ['value' => 'cancelled', 'label' => 'Cancelled'],
                                ['value' => 'rollback', 'label' => 'Rollback'],
                                ['value' => 'executed', 'label' => 'Executed'],
                                ['value' => 'revoked', 'label' => 'Revoked'],
                                ['value' => 'imported', 'label' => 'Imported'],
                            ]" />
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
                    <div class="data-table min-w-[760px] transition-opacity" wire:loading.class="opacity-50 pointer-events-none"
                        wire:target="search,action,source,setPage,previousPage,nextPage">
                        <div class="grid grid-cols-[10rem_minmax(0,1fr)_12rem_9rem] gap-4 border-b border-neutral-200 px-4 py-2 text-[11px] font-medium uppercase tracking-wide text-neutral-500 dark:border-white/[0.07] dark:text-fg-faint">
                            <span>Actor</span>
                            <span>Activity</span>
                            <span>Source</span>
                            <span class="text-right">Time</span>
                        </div>
                        @foreach ($events as $event)
                            <div wire:key="audit-event-{{ $event->id }}"
                                class="grid grid-cols-[10rem_minmax(0,1fr)_12rem_9rem] gap-4 border-b border-neutral-200 px-4 py-3 last:border-b-0 dark:border-white/[0.07]">
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
                                        <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">
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
                                </div>
                                <div class="flex items-center gap-2 text-[12px] text-neutral-500 dark:text-fg-dim">
                                    <span class="rounded-md bg-neutral-100 px-2 py-1 dark:bg-white/[0.06]">
                                        {{ Str::upper($event->source) }}
                                    </span>
                                    <span>{{ Str::headline($event->action) }}</span>
                                </div>
                                <time datetime="{{ $event->created_at->toIso8601String() }}"
                                    title="{{ $event->created_at->toDayDateTimeString() }}"
                                    class="text-right text-[11px] text-neutral-500 dark:text-fg-faint">
                                    {{ $event->created_at->diffForHumans() }}
                                </time>
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
