<div>
    <x-slot:title>
        Scheduled Jobs | Coolify
    </x-slot>

    <x-settings.navbar>
        <x-slot:actions>
            <button type="button" class="button" wire:click="refresh">
                <x-reicon name="refresh" class="size-3.5" />
                Refresh
            </button>
        </x-slot:actions>
    </x-settings.navbar>

    <div class="application-settings-form w-full min-w-0" x-data="{
        activeTab: ['executions', 'scheduler-runs', 'skipped-jobs'].includes(location.hash.slice(1))
            ? location.hash.slice(1)
            : 'executions',
        filterOpen: false,
        sortOpen: false,
        select(tab) {
            this.activeTab = tab;
            history.replaceState(null, '', `#${tab}`);
        }
    }">
        <h1 class="mb-5 text-[24px]! leading-7! font-semibold! tracking-tight!">Scheduled jobs</h1>

        <x-application.settings-section title="Scheduler activity" flush>
            <x-slot:actions>
                <div
                    class="flex items-center gap-0.5 rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                    <button type="button" class="app-tab"
                        :class="activeTab === 'executions' &&
                            'bg-coollabs/10 text-coollabs ring-1 ring-coollabs/25 dark:bg-warning/15 dark:text-warning dark:ring-warning/25'"
                        @click="select('executions')">
                        Failures <span class="ml-1 opacity-60">{{ $executions->count() }}</span>
                    </button>
                    <button type="button" class="app-tab"
                        :class="activeTab === 'scheduler-runs' &&
                            'bg-coollabs/10 text-coollabs ring-1 ring-coollabs/25 dark:bg-warning/15 dark:text-warning dark:ring-warning/25'"
                        @click="select('scheduler-runs')">
                        Scheduler runs <span class="ml-1 opacity-60">{{ $managerRuns->count() }}</span>
                    </button>
                    <button type="button" class="app-tab"
                        :class="activeTab === 'skipped-jobs' &&
                            'bg-coollabs/10 text-coollabs ring-1 ring-coollabs/25 dark:bg-warning/15 dark:text-warning dark:ring-warning/25'"
                        @click="select('skipped-jobs')">
                        Skipped <span class="ml-1 opacity-60">{{ $skipTotalCount }}</span>
                    </button>
                </div>
            </x-slot:actions>
            <div
                class="flex flex-col gap-3 border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                <div x-show="activeTab === 'executions'"
                    class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="relative w-full sm:max-w-sm">
                        <x-reicon name="search"
                            class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                        <input wire:model.live.debounce.250ms="search" type="search"
                            placeholder="Search scheduled jobs"
                            class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-3! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-neutral-300! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="relative" @click.outside="filterOpen = false"
                            @keydown.escape.window="filterOpen = false">
                            <button type="button" class="button" @click="filterOpen = !filterOpen">
                                <x-reicon name="filter" class="size-3.5" />
                                Filter
                            </button>
                            <div x-show="filterOpen" x-cloak x-transition.opacity.duration.120ms
                                class="listbox-panel right-0 left-auto! min-w-52">
                                <div
                                    class="px-2 py-1 text-[10px] font-semibold tracking-wide text-neutral-400 uppercase dark:text-fg-faint">
                                    Type
                                </div>
                                @foreach (['all' => 'All types', 'backup' => 'Backups', 'task' => 'Tasks', 'cleanup' => 'Docker cleanup'] as $value => $label)
                                    <button type="button" class="listbox-option"
                                        wire:click="$set('filterType', '{{ $value }}')"
                                        @click="filterOpen = false">
                                        <span>{{ $label }}</span>
                                        @if ($filterType === $value)
                                            <span class="text-accent">✓</span>
                                        @endif
                                    </button>
                                @endforeach
                                <div
                                    class="mt-1 border-t border-neutral-200 px-2 pt-2 pb-1 text-[10px] font-semibold tracking-wide text-neutral-400 uppercase dark:border-white/[0.08] dark:text-fg-faint">
                                    Time range
                                </div>
                                @foreach (['last_24h' => 'Last 24 hours', 'last_7d' => 'Last 7 days', 'last_30d' => 'Last 30 days', 'all' => 'All time'] as $value => $label)
                                    <button type="button" class="listbox-option"
                                        wire:click="$set('filterDate', '{{ $value }}')"
                                        @click="filterOpen = false">
                                        <span>{{ $label }}</span>
                                        @if ($filterDate === $value)
                                            <span class="text-accent">✓</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <div class="relative" @click.outside="sortOpen = false"
                            @keydown.escape.window="sortOpen = false">
                            <button type="button" class="button" @click="sortOpen = !sortOpen">
                                <x-reicon name="sort-direction" class="size-3.5" />
                                Sort
                            </button>
                            <div x-show="sortOpen" x-cloak x-transition.opacity.duration.120ms
                                class="listbox-panel right-0 left-auto! min-w-44">
                                @foreach (['newest' => 'Newest first', 'oldest' => 'Oldest first'] as $value => $label)
                                    <button type="button" class="listbox-option"
                                        wire:click="$set('sortOrder', '{{ $value }}')"
                                        @click="sortOpen = false">
                                        <span>{{ $label }}</span>
                                        @if ($sortOrder === $value)
                                            <span class="text-accent">✓</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div x-cloak x-show="activeTab === 'executions'">
                @if ($executions->isEmpty())
                    <x-empty title="No failures"
                        description="No failed scheduled executions match the current filters." size="sm">
                        <x-slot:icon>
                            <x-reicon name="check-circle" class="size-6" />
                        </x-slot:icon>
                    </x-empty>
                @else
                    <div x-data="{
                        page: 1,
                        perPage: 10,
                        total: {{ $executions->count() }},
                        get lastPage() { return Math.max(1, Math.ceil(this.total / this.perPage)); },
                        get firstVisibleRow() { return ((this.page - 1) * this.perPage) + 1; },
                        get lastVisibleRow() { return Math.min(this.page * this.perPage, this.total); },
                        goToPage(page) { this.page = Math.min(Math.max(page, 1), this.lastPage); }
                    }">
                        <div class="data-table">
                            <div class="data-table-header scheduled-executions-table-grid">
                                <span>Type</span>
                                <span>Resource</span>
                                <span>Server</span>
                                <span>Started</span>
                                <span>Duration</span>
                                <span>Message</span>
                            </div>
                            @foreach ($executions as $execution)
                                @php
                                    $typeLabel = match ($execution['type']) {
                                        'backup' => 'Backup',
                                        'task' => 'Task',
                                        'cleanup' => 'Cleanup',
                                        default => ucfirst($execution['type']),
                                    };
                                @endphp
                                <div wire:key="exec-{{ $execution['type'] }}-{{ $execution['id'] }}"
                                    x-show="{{ $loop->index }} >= (page - 1) * perPage && {{ $loop->index }} < page * perPage"
                                    class="data-table-row scheduled-executions-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                                    <div>
                                        <span
                                            class="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                            {{ $typeLabel }}
                                        </span>
                                    </div>
                                    <div class="min-w-0 truncate text-[12px] font-medium text-black dark:text-fg">
                                        {{ $execution['resource_name'] }}
                                        @if ($execution['resource_type'])
                                            <span class="text-[10px] font-normal text-neutral-400">
                                                {{ $execution['resource_type'] }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="truncate text-[11px] text-neutral-500 dark:text-fg-dim">
                                        {{ $execution['server_name'] }}
                                    </div>
                                    <div class="text-[11px] text-neutral-500 dark:text-fg-dim">
                                        {{ $execution['created_at']->format('Y-m-d H:i') }}
                                    </div>
                                    <div class="text-[11px] text-neutral-500 dark:text-fg-dim">
                                        @if ($execution['finished_at'] && $execution['created_at'])
                                            {{ \Carbon\Carbon::parse($execution['created_at'])->diffInSeconds(\Carbon\Carbon::parse($execution['finished_at'])) }}s
                                        @elseif ($execution['status'] === 'running')
                                            <x-loading class="size-3.5" />
                                        @else
                                            —
                                        @endif
                                    </div>
                                    <div class="min-w-0 truncate text-[11px] text-neutral-500 dark:text-fg-dim"
                                        title="{{ $execution['message'] }}">
                                        {{ Str::limit($execution['message'], 80) }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div
                            class="flex min-h-12 items-center justify-between gap-3 border-t border-neutral-200 px-4 dark:border-white/[0.08]">
                            <span class="whitespace-nowrap text-[11px] text-neutral-500 dark:text-fg-faint">
                                Showing <span x-text="`${firstVisibleRow}–${lastVisibleRow}`"></span> of
                                {{ $executions->count() }}
                            </span>
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] text-neutral-500 dark:text-fg-dim">
                                    Page <span x-text="page"></span> of <span x-text="lastPage"></span>
                                </span>
                                <button type="button" class="button size-8! px-0!" x-on:click="goToPage(page - 1)"
                                    x-bind:disabled="page === 1" aria-label="Previous page">
                                    <x-reicon name="arrow-right" class="size-3.5 rotate-180" />
                                </button>
                                <button type="button" class="button size-8! px-0!" x-on:click="goToPage(page + 1)"
                                    x-bind:disabled="page === lastPage" aria-label="Next page">
                                    <x-reicon name="arrow-right" class="size-3.5" />
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div x-cloak x-show="activeTab === 'scheduler-runs'">
                @if ($managerRuns->isEmpty())
                    <x-empty title="No manager runs"
                        description="Scheduler manager activity appears here after its next run." size="sm">
                        <x-slot:icon>
                            <x-reicon name="refresh" class="size-6" />
                        </x-slot:icon>
                    </x-empty>
                @else
                    <div x-data="{
                        page: 1,
                        perPage: 10,
                        total: {{ $managerRuns->count() }},
                        get lastPage() { return Math.max(1, Math.ceil(this.total / this.perPage)); },
                        get firstVisibleRow() { return ((this.page - 1) * this.perPage) + 1; },
                        get lastVisibleRow() { return Math.min(this.page * this.perPage, this.total); },
                        goToPage(page) { this.page = Math.min(Math.max(page, 1), this.lastPage); }
                    }">
                        <div class="data-table">
                            <div class="data-table-header scheduler-runs-table-grid">
                                <span>Time</span>
                                <span>Event</span>
                                <span>Duration</span>
                                <span>Dispatched</span>
                                <span>Skipped</span>
                            </div>
                            @foreach ($managerRuns as $run)
                                <div wire:key="run-{{ $loop->index }}"
                                    x-show="{{ $loop->index }} >= (page - 1) * perPage && {{ $loop->index }} < page * perPage"
                                    class="data-table-row scheduler-runs-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                                    <div class="font-mono text-[11px] text-neutral-500 dark:text-fg-dim">
                                        {{ $run['timestamp'] }}
                                    </div>
                                    <div class="min-w-0 truncate text-[12px] text-black dark:text-fg">
                                        {{ $run['message'] }}
                                    </div>
                                    <div class="text-[11px] text-neutral-500 dark:text-fg-dim">
                                        {{ $run['duration_ms'] !== null ? $run['duration_ms'] . 'ms' : '—' }}
                                    </div>
                                    <div class="text-[11px] text-neutral-500 dark:text-fg-dim">
                                        {{ $run['dispatched'] ?? '—' }}
                                    </div>
                                    <div class="text-[11px] text-neutral-500 dark:text-fg-dim">
                                        {{ $run['skipped'] ?? '—' }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div
                            class="flex min-h-12 items-center justify-between gap-3 border-t border-neutral-200 px-4 dark:border-white/[0.08]">
                            <span class="whitespace-nowrap text-[11px] text-neutral-500 dark:text-fg-faint">
                                Showing <span x-text="`${firstVisibleRow}–${lastVisibleRow}`"></span> of
                                {{ $managerRuns->count() }}
                            </span>
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] text-neutral-500 dark:text-fg-dim">
                                    Page <span x-text="page"></span> of <span x-text="lastPage"></span>
                                </span>
                                <button type="button" class="button size-8! px-0!" x-on:click="goToPage(page - 1)"
                                    x-bind:disabled="page === 1" aria-label="Previous page">
                                    <x-reicon name="arrow-right" class="size-3.5 rotate-180" />
                                </button>
                                <button type="button" class="button size-8! px-0!" x-on:click="goToPage(page + 1)"
                                    x-bind:disabled="page === lastPage" aria-label="Next page">
                                    <x-reicon name="arrow-right" class="size-3.5" />
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div x-cloak x-show="activeTab === 'skipped-jobs'">
                @if ($skipLogs->isEmpty())
                    <x-empty title="No skipped jobs"
                        description="All scheduled jobs met their dispatch conditions." size="sm">
                        <x-slot:icon>
                            <x-reicon name="check-circle" class="size-6" />
                        </x-slot:icon>
                    </x-empty>
                @else
                    <div class="data-table">
                        <div class="data-table-header skipped-jobs-table-grid">
                            <span>Time</span>
                            <span>Type</span>
                            <span>Resource</span>
                            <span>Reason</span>
                        </div>
                        @foreach ($skipLogs as $skip)
                            @php
                                $reasonLabel = match ($skip['reason']) {
                                    'server_not_functional' => 'Server not functional',
                                    'subscription_unpaid' => 'Subscription unpaid',
                                    'database_deleted' => 'Database deleted',
                                    'server_deleted' => 'Server deleted',
                                    'resource_deleted' => 'Resource deleted',
                                    'application_not_running' => 'Application not running',
                                    'service_not_running' => 'Service not running',
                                    default => ucfirst(str_replace('_', ' ', $skip['reason'])),
                                };
                            @endphp
                            <div wire:key="skip-{{ $loop->index }}"
                                class="data-table-row skipped-jobs-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                                <div class="font-mono text-[11px] text-neutral-500 dark:text-fg-dim">
                                    {{ $skip['timestamp'] }}
                                </div>
                                <div>
                                    <span
                                        class="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium capitalize text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                        {{ str_replace('_', ' ', $skip['type']) }}
                                    </span>
                                </div>
                                <div class="min-w-0 truncate text-[12px] text-black dark:text-fg">
                                    @if ($skip['link'] ?? null)
                                        <a href="{{ $skip['link'] }}"
                                            class="font-medium text-coollabs hover:underline dark:text-warning">
                                            {{ $skip['resource_name'] }}
                                        </a>
                                    @else
                                        {{ $skip['resource_name'] ?? $skip['context']['task_name'] ?? $skip['context']['server_name'] ?? 'Deleted resource' }}
                                    @endif
                                </div>
                                <div class="min-w-0 truncate text-[11px] text-neutral-500 dark:text-fg-dim">
                                    {{ $reasonLabel }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div
                        class="flex min-h-12 items-center justify-between gap-3 border-t border-neutral-200 px-4 dark:border-white/[0.08]">
                        <span class="whitespace-nowrap text-[11px] text-neutral-500 dark:text-fg-faint">
                            {{ $skipTotalCount }} {{ Str::plural('skipped job', $skipTotalCount) }}
                        </span>
                        @if ($skipTotalCount > $skipDefaultTake)
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] text-neutral-500 dark:text-fg-dim">
                                    Page {{ $skipCurrentPage }} of {{ ceil($skipTotalCount / $skipDefaultTake) }}
                                </span>
                                <button type="button" class="button size-8! px-0!" wire:click="skipPreviousPage"
                                    @disabled(!$showSkipPrev) aria-label="Previous page">
                                    <x-reicon name="arrow-right" class="size-3.5 rotate-180" />
                                </button>
                                <button type="button" class="button size-8! px-0!" wire:click="skipNextPage"
                                    @disabled(!$showSkipNext) aria-label="Next page">
                                    <x-reicon name="arrow-right" class="size-3.5" />
                                </button>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </x-application.settings-section>
    </div>
</div>
