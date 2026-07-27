<div>
    <x-slot:title>{{ data_get_str($application, 'name')->limit(10) }} > Deployments | Coolify</x-slot>
    <livewire:project.shared.configuration-checker :resource="$application" />
    <livewire:project.application.heading :application="$application" />

    @php
        $lastPage = max(1, (int) ceil($deployments_count / $defaultTake));
        $firstVisibleRow = $deployments_count === 0 ? 0 : $skip + 1;
        $lastVisibleRow = min($skip + $deployments->count(), $deployments_count);
        $hasActiveFilter = $deploymentFilter !== 'all' || filled($pull_request_id);
        $hasActiveQuery = trim($search) !== '' || $hasActiveFilter;
    @endphp

    <div class="application-settings-form"
        @if (!$skip) wire:poll.5000ms="reloadDeployments" @endif>
        <x-application.settings-section title="Deployment history"
            helper="Search, filter, and open a deployment to inspect its build logs." flush>
            <div
                class="flex flex-wrap items-center gap-2 border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                <div class="relative min-w-0 max-w-md flex-1">
                    <input type="search" placeholder="Search deployments" aria-label="Search deployments"
                        wire:model.live.debounce.300ms="search" class="input w-full pl-8!" />
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5">
                        <x-reicon name="search" wire:loading.remove wire:target="search"
                            class="size-3.5 text-neutral-400 dark:text-fg-faint" />
                        <svg wire:loading wire:target="search" aria-hidden="true"
                            class="size-3.5 animate-spin text-neutral-400 dark:text-fg-dim" fill="none"
                            viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10"
                                stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                        </svg>
                    </div>
                </div>

                <div class="ml-auto flex items-center gap-2">
                    <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                        <button type="button" class="button" @click="open = !open"
                            @click.outside="open = false" aria-haspopup="listbox" :aria-expanded="open">
                            <x-reicon name="filter" class="size-3.5" />
                            Filter
                        </button>
                        <div class="listbox-panel left-auto! right-0 w-48!" x-show="open" x-cloak
                            role="listbox">
                            <button type="button" class="listbox-option" role="option"
                                aria-selected="{{ !$hasActiveFilter ? 'true' : 'false' }}"
                                wire:click="clearFilter" @click="open = false">
                                <span>All deployments</span>
                                @if (!$hasActiveFilter)
                                    <svg class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none">
                                        <path d="m4.5 12.75 6 6 9-13.5" stroke="currentColor"
                                            stroke-width="2.5" stroke-linecap="round"
                                            stroke-linejoin="round" />
                                    </svg>
                                @endif
                            </button>

                            @if (count($statusFilterOptions) > 0)
                                <span
                                    class="px-2 pb-1 pt-2 text-[10px] font-medium uppercase tracking-wider text-neutral-400 dark:text-fg-faint">Status</span>
                                @foreach ($statusFilterOptions as $option)
                                    <button type="button" class="listbox-option" role="option"
                                        aria-selected="{{ $deploymentFilter === $option['value'] ? 'true' : 'false' }}"
                                        wire:click="setDeploymentFilter('{{ $option['value'] }}')"
                                        @click="open = false">
                                        <span>{{ $option['label'] }}</span>
                                        @if ($deploymentFilter === $option['value'] && !$pull_request_id)
                                            <svg class="size-3.5 shrink-0" viewBox="0 0 24 24"
                                                fill="none">
                                                <path d="m4.5 12.75 6 6 9-13.5" stroke="currentColor"
                                                    stroke-width="2.5" stroke-linecap="round"
                                                    stroke-linejoin="round" />
                                            </svg>
                                        @endif
                                    </button>
                                @endforeach
                            @endif

                            @if (count($sourceFilterOptions) > 1)
                                <span
                                    class="px-2 pb-1 pt-2 text-[10px] font-medium uppercase tracking-wider text-neutral-400 dark:text-fg-faint">Source</span>
                                @foreach ($sourceFilterOptions as $option)
                                    <button type="button" class="listbox-option" role="option"
                                        aria-selected="{{ $deploymentFilter === $option['value'] ? 'true' : 'false' }}"
                                        wire:click="setDeploymentFilter('{{ $option['value'] }}')"
                                        @click="open = false">
                                        <span>{{ $option['label'] }}</span>
                                        @if ($deploymentFilter === $option['value'] && !$pull_request_id)
                                            <svg class="size-3.5 shrink-0" viewBox="0 0 24 24"
                                                fill="none">
                                                <path d="m4.5 12.75 6 6 9-13.5" stroke="currentColor"
                                                    stroke-width="2.5" stroke-linecap="round"
                                                    stroke-linejoin="round" />
                                            </svg>
                                        @endif
                                    </button>
                                @endforeach
                            @endif

                            @if (count($pullRequestOptions) > 1)
                                <span
                                    class="px-2 pb-1 pt-2 text-[10px] font-medium uppercase tracking-wider text-neutral-400 dark:text-fg-faint">Pull request</span>
                                @foreach (array_slice($pullRequestOptions, 1) as $option)
                                    <button type="button" class="listbox-option" role="option"
                                        aria-selected="{{ $pull_request_id === $option['value'] ? 'true' : 'false' }}"
                                        wire:click="setPullRequestFilter('{{ $option['value'] }}')"
                                        @click="open = false">
                                        <span>{{ $option['label'] }}</span>
                                        @if ($pull_request_id === $option['value'])
                                            <svg class="size-3.5 shrink-0" viewBox="0 0 24 24"
                                                fill="none">
                                                <path d="m4.5 12.75 6 6 9-13.5" stroke="currentColor"
                                                    stroke-width="2.5" stroke-linecap="round"
                                                    stroke-linejoin="round" />
                                            </svg>
                                        @endif
                                    </button>
                                @endforeach
                            @endif
                        </div>
                    </div>

                    <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
                        <button type="button" class="button" @click="open = !open"
                            @click.outside="open = false" aria-haspopup="listbox" :aria-expanded="open">
                            <x-reicon name="sort-direction" class="size-3.5" />
                            Sort
                        </button>
                        <div class="listbox-panel left-auto! right-0 w-40!" x-show="open" x-cloak
                            role="listbox">
                            @foreach ([
                                'newest' => 'Newest first',
                                'oldest' => 'Oldest first',
                            ] as $sortValue => $sortLabel)
                                <button type="button" class="listbox-option" role="option"
                                    aria-selected="{{ $deploymentSort === $sortValue ? 'true' : 'false' }}"
                                    wire:click="setDeploymentSort('{{ $sortValue }}')"
                                    @click="open = false">
                                    <span>{{ $sortLabel }}</span>
                                    @if ($deploymentSort === $sortValue)
                                        <svg class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none">
                                            <path d="m4.5 12.75 6 6 9-13.5" stroke="currentColor"
                                                stroke-width="2.5" stroke-linecap="round"
                                                stroke-linejoin="round" />
                                        </svg>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            @if ($deployments->isNotEmpty())
                <div class="data-table w-full">
                    <div class="data-table-header deployment-table-grid rounded-none!">
                        <span>Status</span>
                        <span>Source</span>
                        <span>Commit</span>
                        <span>Started</span>
                        <span>Duration</span>
                        <span>Server</span>
                        <span></span>
                    </div>

                    @foreach ($deployments as $deployment)
                        @php
                            $deploymentStatus = data_get($deployment, 'status');
                            $statusLabel = match ($deploymentStatus) {
                                'finished' => 'Success',
                                'in_progress' => 'In progress',
                                'cancelled-by-user' => 'Cancelled',
                                'queued' => 'Queued',
                                default => str($deploymentStatus)->headline()->toString(),
                            };
                            $statusType = match ($deploymentStatus) {
                                'finished' => 'success',
                                'in_progress', 'queued' => 'warning',
                                'failed' => 'error',
                                default => 'neutral',
                            };
                            $pullRequestId = (int) data_get($deployment, 'pull_request_id', 0);
                            $sourceLabel = match (true) {
                                (bool) data_get($deployment, 'is_webhook') && $pullRequestId > 0 => 'Webhook · PR #' . $pullRequestId,
                                (bool) data_get($deployment, 'is_webhook') => 'Webhook',
                                $pullRequestId > 0 => 'Pull request #' . $pullRequestId,
                                (bool) data_get($deployment, 'rollback') => 'Rollback',
                                (bool) data_get($deployment, 'is_api') => 'API',
                                default => 'Manual',
                            };
                            $duration = match ($deploymentStatus) {
                                'queued' => 'Waiting',
                                'in_progress' => calculateDuration(data_get($deployment, 'created_at'), now()),
                                default => data_get($deployment, 'finished_at')
                                    ? calculateDuration(data_get($deployment, 'created_at'), data_get($deployment, 'finished_at'))
                                    : '—',
                            };
                            $commitMessage = $deployment->commitMessage()
                                ? Str::before($deployment->commitMessage(), "\n")
                                : null;
                        @endphp
                        <a wire:key="deployment-{{ data_get($deployment, 'deployment_uuid') }}"
                            href="{{ $current_url . '/' . data_get($deployment, 'deployment_uuid') }}"
                            {{ wireNavigate() }}
                            class="data-table-row deployment-table-grid border-b border-neutral-200 text-[13px] text-neutral-600 dark:border-white/[0.08] dark:text-fg-dim">
                            <span><x-status-badge :status="$statusLabel" :type="$statusType" /></span>
                            <span>{{ $sourceLabel }}</span>
                            <span class="min-w-0">
                                @if (data_get($deployment, 'commit'))
                                    <span class="flex min-w-0 items-center gap-2">
                                        <span class="shrink-0 font-mono text-xs text-neutral-950 dark:text-fg">
                                            {{ substr(data_get($deployment, 'commit'), 0, 7) }}
                                        </span>
                                        @if ($commitMessage)
                                            <span class="truncate text-neutral-500 dark:text-fg-faint"
                                                title="{{ $commitMessage }}">{{ $commitMessage }}</span>
                                        @endif
                                    </span>
                                @else
                                    <span class="text-neutral-400 dark:text-fg-faint">—</span>
                                @endif
                            </span>
                            <span title="{{ formatDateInServerTimezone(data_get($deployment, 'created_at'), data_get($application, 'destination.server')) }}">
                                {{ \Carbon\Carbon::parse(data_get($deployment, 'created_at'))->diffForHumans() }}
                            </span>
                            <span class="tabular-nums">{{ $duration }}</span>
                            <span class="truncate">
                                {{ data_get($deployment, 'server_name') ?: data_get($application, 'destination.server.name', '—') }}
                            </span>
                            <span class="flex justify-end text-neutral-400 dark:text-fg-faint">
                                <x-reicon name="arrow-right" class="size-3.5" />
                            </span>
                        </a>
                    @endforeach

                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                        <p class="shrink-0 whitespace-nowrap text-[13px] text-neutral-500 dark:text-fg-dim">
                            Showing <span
                                class="tabular-nums text-black dark:text-fg">{{ $firstVisibleRow }}–{{ $lastVisibleRow }}</span>
                            of <span class="tabular-nums text-black dark:text-fg">{{ $deployments_count }}</span>
                        </p>
                        <div
                            class="inline-flex h-8 overflow-hidden rounded-lg border border-neutral-200 dark:border-white/[0.10]">
                            <button type="button"
                                class="flex w-10 items-center justify-center border-r border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:border-white/[0.10] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                aria-label="First page" title="First page" wire:click="goToPage(1)"
                                @disabled($currentPage === 1)>
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none">
                                    <path d="M18 6L12 12L18 18M11 6L5 12L11 18" stroke="currentColor"
                                        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button type="button"
                                class="flex w-10 items-center justify-center border-r border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:border-white/[0.10] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                aria-label="Previous page" title="Previous page" wire:click="previousPage"
                                @disabled($currentPage === 1)>
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none">
                                    <path d="M15 6L9 12L15 18" stroke="currentColor" stroke-width="1.8"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <span
                                class="flex min-w-12 items-center justify-center border-r border-neutral-200 px-3 text-[13px] tabular-nums text-black dark:border-white/[0.10] dark:text-fg">
                                {{ $currentPage }}
                            </span>
                            <button type="button"
                                class="flex w-10 items-center justify-center border-r border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:border-white/[0.10] dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                aria-label="Next page" title="Next page" wire:click="nextPage"
                                @disabled($currentPage >= $lastPage)>
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none">
                                    <path d="M9 6L15 12L9 18" stroke="currentColor" stroke-width="1.8"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <button type="button"
                                class="flex w-10 items-center justify-center text-neutral-500 transition-colors hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-35 dark:text-fg-dim dark:hover:bg-white/[0.06]"
                                aria-label="Last page" title="Last page" wire:click="goToPage({{ $lastPage }})"
                                @disabled($currentPage >= $lastPage)>
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none">
                                    <path d="M6 6L12 12L6 18M13 6L19 12L13 18" stroke="currentColor"
                                        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            @else
                <x-empty size="sm" title="No deployments found"
                    :description="$hasActiveQuery
                        ? 'No deployments match the current search and filters.'
                        : 'Deploy the application to create its first deployment record.'">
                    <x-slot:icon>
                        <x-reicon name="layers" class="size-8" />
                    </x-slot:icon>
                </x-empty>
            @endif
        </x-application.settings-section>
    </div>
</div>
