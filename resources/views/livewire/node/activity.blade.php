<div>
    <x-slot:title>
        Activity | {{ data_get_str($node, 'name')->limit(24) }} | Coolify
    </x-slot>

    <x-node.navbar :node="$node" />

    <div
        class="node-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-node.sidebar :node="$node" activeMenu="activity" />

        <div class="flex w-full min-w-0 flex-col gap-6">
            <x-application.settings-section id="node-activity-section" title="Activity" flush
                helper="Deploy, lifecycle, move, and server operations on this server. Background checks only appear when they fail.">
                <x-slot:actions>
                    <x-forms.button type="button" wire:click="refreshActivity" title="Refresh activity">
                        <x-reicon name="refresh" class="size-3.5" />
                        Refresh
                    </x-forms.button>
                </x-slot:actions>

                <x-table.toolbar class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                    <x-table.filter :active-count="count($statusFilters) + count($applicationFilters)"
                        reset-action="clearFilters">
                        @php
                            $filterGroups = [
                                ['label' => 'Status', 'options' => $statusOptions, 'selected' => $statusFilters, 'action' => 'toggleStatusFilter'],
                                ['label' => 'Application', 'options' => $applicationOptions, 'selected' => $applicationFilters, 'action' => 'toggleApplicationFilter'],
                            ];
                        @endphp
                        @foreach ($filterGroups as $filterGroup)
                            @continue($filterGroup['options'] === [])
                            <span
                                class="px-2 pb-1 pt-2 text-[10px] font-medium uppercase tracking-wider text-neutral-400 dark:text-fg-faint">{{ $filterGroup['label'] }}</span>
                            @foreach ($filterGroup['options'] as $option)
                                @php
                                    $selected = in_array($option['value'], $filterGroup['selected'], true);
                                @endphp
                                <button type="button" class="listbox-option" role="option"
                                    wire:key="node-activity-filter-{{ $filterGroup['action'] }}-{{ $option['value'] }}"
                                    aria-selected="{{ $selected ? 'true' : 'false' }}"
                                    wire:click="{{ $filterGroup['action'] }}('{{ $option['value'] }}')">
                                    <span class="truncate" title="{{ $option['label'] }}">{{ $option['label'] }}</span>
                                    <span @class([
                                        'flex size-4 shrink-0 items-center justify-center rounded-[5px] border',
                                        'border-coollabs bg-coollabs text-white dark:border-warning dark:bg-warning dark:text-black' => $selected,
                                        'border-neutral-300 bg-white dark:border-white/[0.14] dark:bg-white/[0.045]' => ! $selected,
                                    ])>
                                        @if ($selected)
                                            <svg class="size-3" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                                <path d="m2.25 6.15 2.35 2.3 5.15-5" stroke="currentColor"
                                                    stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        @endif
                                    </span>
                                </button>
                            @endforeach
                        @endforeach
                    </x-table.filter>
                </x-table.toolbar>

                @if ($rows !== [])
                    <div class="data-table relative w-full transition-opacity"
                        wire:loading.class="opacity-50 pointer-events-none"
                        wire:target="goToPage,previousPage,nextPage,toggleStatusFilter,toggleApplicationFilter,clearFilters">
                        <x-table.loading target="toggleStatusFilter,toggleApplicationFilter,clearFilters"
                            text="Filtering activity..." class="rounded-lg" />
                        <div class="data-table-header node-activity-table-grid rounded-none!">
                            <span>Action</span>
                            <span class="hidden md:block">Application</span>
                            <span>Status</span>
                            <span class="hidden md:block">Requested by</span>
                            <span class="hidden md:block">Time</span>
                        </div>

                        @foreach ($rows as $row)
                            <div wire:key="node-activity-{{ $row['uuid'] }}" data-node-activity-row
                                class="data-table-row node-activity-table-grid border-b border-neutral-200 text-[13px] text-neutral-600 last:border-b-0 dark:border-white/[0.08] dark:text-fg-dim">
                                <div class="min-w-0">
                                    @if ($row['actionHref'])
                                        <a href="{{ $row['actionHref'] }}" {{ wireNavigate() }}
                                            class="block truncate font-medium text-black hover:underline dark:text-fg">{{ $row['action'] }}</a>
                                    @else
                                        <span class="block truncate font-medium text-black dark:text-fg"
                                            @if ($row['error']) title="{{ $row['error'] }}" @endif>{{ $row['action'] }}</span>
                                    @endif
                                    <span class="mt-0.5 block truncate text-[12px] text-neutral-500 md:hidden dark:text-fg-faint">
                                        {{ collect([$row['application'], $row['createdAt']->diffForHumans()])->filter()->join(' · ') }}
                                    </span>
                                </div>
                                <div class="hidden min-w-0 md:block">
                                    @if ($row['applicationHref'])
                                        <a href="{{ $row['applicationHref'] }}" {{ wireNavigate() }}
                                            class="block truncate hover:underline">{{ $row['application'] }}</a>
                                    @else
                                        <span class="block truncate">{{ $row['application'] ?? '-' }}</span>
                                    @endif
                                </div>
                                <div class="flex min-w-0 items-center gap-2">
                                    <x-status-badge :status="$row['status']" :type="$row['statusType']"
                                        :title="$row['error']" />
                                    @if ($row['canRecover'])
                                        @can('update', $node)
                                            <x-forms.button class="h-6! px-2! text-xs!"
                                                wire:click="retryOperation('{{ $row['uuid'] }}')">
                                                Recover
                                            </x-forms.button>
                                        @endcan
                                    @endif
                                </div>
                                <span class="hidden truncate md:block">{{ $row['requestedBy'] }}</span>
                                <span class="hidden md:block" title="{{ $row['createdAt']->toIso8601String() }}">
                                    {{ $row['createdAt']->diffForHumans() }}
                                </span>
                            </div>
                        @endforeach

                        @if ($pagination['lastPage'] > 1)
                            <x-table-pagination :from="$pagination['from']" :to="$pagination['to']"
                                :total="$pagination['total']" :current-page="$pagination['currentPage']"
                                :last-page="$pagination['lastPage']" wire-target="goToPage,previousPage,nextPage"
                                first-action="goToPage(1)" previous-action="previousPage" next-action="nextPage"
                                last-action="goToPage({{ $pagination['lastPage'] }})" />
                        @endif
                    </div>
                @elseif ($hasActiveFilter)
                    <div class="p-4">
                        <x-empty size="sm" title="No matching activity"
                            description="No operations match the selected filters." icon-name="filter" />
                    </div>
                @else
                    <div class="p-4">
                        <x-empty size="sm" title="No activity yet"
                            description="Deployments, lifecycle actions, and moves on this server appear here."
                            icon-name="time-back" />
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </div>
</div>
