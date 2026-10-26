<div>
    @unless ($embedded)
        <x-slot:title>{{ data_get_str($application, 'name')->limit(10) }} > Deployments | Coolify</x-slot>
        <livewire:project.shared.configuration-checker :resource="$application" />
        <livewire:project.application.heading :application="$application" wire:key="application-heading-deployment-index" />
    @endunless

    @php
        $lastPage = max(1, (int) ceil($deployments_count / $defaultTake));
        $firstVisibleRow = $deployments_count === 0 ? 0 : $skip + 1;
        $lastVisibleRow = min($skip + $deployments->count(), $deployments_count);
        $hasActiveFilter = count($deploymentFilters) > 0 || filled($pull_request_id);
        $hasActiveQuery = trim($search) !== '' || $hasActiveFilter;
    @endphp

    <section @class([
        'application-settings-workspace w-full',
        'mt-4 max-w-none lg:mt-0' => ! $embedded,
    ])>
        <div @class([
            'min-w-0',
            'grid gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8' => ! $embedded,
        ])>
            @unless ($embedded)
                <x-application.configuration-sidebar :application="$application"
                    current-route="project.application.deployment.index" />
            @endunless

            <div @class(['application-settings-form min-w-0', 'deployment-history-fit' => $embedded])
        @if (!$skip) wire:poll.5000ms="reloadDeployments" @endif
        @if ($embedded)
            x-data="{
                selecting: null,
                fit() {
                    const scroll = this.$el.querySelector('.deployment-table-scroll');
                    const header = scroll?.querySelector('.data-table-header');
                    const row = scroll?.querySelector('.data-table-row');
                    if (!row) return;
                    const take = window.matchMedia('(min-width: 1536px)').matches
                        ? Math.max(1, Math.floor((scroll.clientHeight - header.offsetHeight) / row.offsetHeight))
                        : 3;
                    if (take !== $wire.defaultTake) $wire.set('defaultTake', take);
                },
            }"
            x-init="let timer;
                new ResizeObserver(() => { clearTimeout(timer); timer = setTimeout(() => fit(), 100); }).observe($el);
                $wire.$hook('commit', ({ succeed }) => succeed(() => $nextTick(() => fit())))"
        @endif>
        <x-application.settings-section title="Deployment history"
            helper="Search, filter, and open a deployment to inspect its build logs." flush>
            <x-table.toolbar class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                <x-slot:search>
                    <x-table.search placeholder="Search deployments" loading-target="search"
                        wire:model.live.debounce.300ms="search" />
                </x-slot:search>
                <x-table.filter :active-count="count($deploymentFilters) + (filled($pull_request_id) ? 1 : 0)"
                    :active-text="filled($pull_request_id) ? 'Pull request #'.$pull_request_id : null"
                    reset-action="clearFilter">
                            @if (count($statusFilterOptions) > 0)
                                <span
                                    class="px-2 pb-1 pt-2 text-[10px] font-medium uppercase tracking-wider text-neutral-400 dark:text-fg-faint">Status</span>
                                @foreach ($statusFilterOptions as $option)
                                    <button type="button" class="listbox-option" role="option"
                                        aria-selected="{{ in_array($option['value'], $deploymentFilters, true) ? 'true' : 'false' }}"
                                        wire:click="toggleDeploymentFilter('{{ $option['value'] }}')">
                                        <span>{{ $option['label'] }}</span>
                                        @php
                                            $selected = in_array($option['value'], $deploymentFilters, true);
                                        @endphp
                                        <span @class([
                                            'flex size-4 shrink-0 items-center justify-center rounded-[5px] border',
                                            'border-coollabs bg-coollabs text-white dark:border-brand dark:bg-brand dark:text-white' => $selected,
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
                            @endif

                            @if (count($sourceFilterOptions) > 0)
                                <span
                                    class="px-2 pb-1 pt-2 text-[10px] font-medium uppercase tracking-wider text-neutral-400 dark:text-fg-faint">Source</span>
                                @foreach ($sourceFilterOptions as $option)
                                    <button type="button" class="listbox-option" role="option"
                                        aria-selected="{{ in_array($option['value'], $deploymentFilters, true) ? 'true' : 'false' }}"
                                        wire:click="toggleDeploymentFilter('{{ $option['value'] }}')">
                                        <span>{{ $option['label'] }}</span>
                                        @php
                                            $selected = in_array($option['value'], $deploymentFilters, true);
                                        @endphp
                                        <span @class([
                                            'flex size-4 shrink-0 items-center justify-center rounded-[5px] border',
                                            'border-coollabs bg-coollabs text-white dark:border-brand dark:bg-brand dark:text-white' => $selected,
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
                            @endif

                            @if (count($serverFilterOptions) > 0)
                                <span
                                    class="px-2 pb-1 pt-2 text-[10px] font-medium uppercase tracking-wider text-neutral-400 dark:text-fg-faint">Server</span>
                                @foreach ($serverFilterOptions as $option)
                                    <button type="button" class="listbox-option" role="option"
                                        aria-selected="{{ in_array($option['value'], $deploymentFilters, true) ? 'true' : 'false' }}"
                                        wire:click="toggleDeploymentFilter('{{ $option['value'] }}')">
                                        <span class="truncate">{{ $option['label'] }}</span>
                                        @php
                                            $selected = in_array($option['value'], $deploymentFilters, true);
                                        @endphp
                                        <span @class([
                                            'flex size-4 shrink-0 items-center justify-center rounded-[5px] border',
                                            'border-coollabs bg-coollabs text-white dark:border-brand dark:bg-brand dark:text-white' => $selected,
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
                </x-table.filter>
                <x-table.sort>
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
                </x-table.sort>
            </x-table.toolbar>

            @if ($deployments->isNotEmpty())
                <div class="data-table relative w-full transition-opacity"
                    wire:loading.class="opacity-50 pointer-events-none"
                    wire:target="goToPage,previousPage,nextPage,toggleDeploymentFilter,clearFilter,setPullRequestFilter">
                    <x-table.loading id="deployment-table-filter-loading"
                        target="toggleDeploymentFilter,clearFilter,setPullRequestFilter"
                        text="Filtering deployments..." class="rounded-lg" />
                    <div @class(['deployment-table-scroll', 'deployment-table-embedded' => $embedded])
                        @if ($embedded)
                            @click="const link = $event.target.closest('a[data-select-deployment]');
                                if (!link || $event.button !== 0 || $event.metaKey || $event.ctrlKey || $event.shiftKey || $event.altKey) return;
                                $event.preventDefault();
                                selecting = link.dataset.selectDeployment;
                                $wire.$parent.selectDeployment(selecting).finally(() => selecting = null)"
                        @endif>
                        <div class="data-table-header deployment-table-grid rounded-none!">
                            @unless ($embedded)
                                <span>Status</span>
                            @endunless
                            <span>Source</span>
                            <span>Commit</span>
                            @unless ($embedded)
                                <span>Started</span>
                            @endunless
                            <span>Duration</span>
                            <span>Server</span>
                            @if ($embedded)
                                <span class="text-right">Status</span>
                            @endif
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
                                    : '-',
                            };
                            $commitMessage = $deployment->commitMessage()
                                ? Str::before($deployment->commitMessage(), "\n")
                                : null;
                            $deploymentUrl = $current_url . '/' . data_get($deployment, 'deployment_uuid');
                            $deploymentLinkAttributes = $embedded
                                ? 'data-select-deployment="'.e(data_get($deployment, 'deployment_uuid')).'"'
                                : wireNavigate();
                            $commitUrl = data_get($deployment, 'commit')
                                ? $application->gitCommitLink(data_get($deployment, 'commit'))
                                : null;
                        @endphp
                        <div wire:key="deployment-{{ data_get($deployment, 'deployment_uuid') }}"
                            @class([
                                'data-table-row deployment-table-row deployment-table-grid group relative border-b border-neutral-200 text-[13px] text-neutral-600 hover:text-neutral-950 dark:border-white/[0.08] dark:text-fg-dim dark:hover:text-white',
                                'data-table-row-active' => $selectedDeploymentUuid === data_get($deployment, 'deployment_uuid'),
                            ])
                            @if ($embedded)
                                x-bind:class="selecting === '{{ data_get($deployment, 'deployment_uuid') }}' && 'data-table-row-active'"
                                x-bind:aria-busy="selecting === '{{ data_get($deployment, 'deployment_uuid') }}'"
                            @endif>
                            @unless ($embedded)
                                <a href="{{ $deploymentUrl }}" {!! $deploymentLinkAttributes !!}><x-status-badge :status="$statusLabel" :type="$statusType" /></a>
                            @endunless
                            <a href="{{ $deploymentUrl }}" {!! $deploymentLinkAttributes !!}>{{ $sourceLabel }}</a>
                            <span class="min-w-0">
                                @if (data_get($deployment, 'commit'))
                                    <span class="flex min-w-0 items-center gap-2">
                                        <a href="{{ $commitUrl }}" target="_blank" rel="noopener noreferrer"
                                            class="shrink-0 font-mono text-xs text-neutral-950 underline decoration-neutral-400 underline-offset-2 dark:text-fg dark:decoration-neutral-600 group-hover:text-neutral-950 dark:group-hover:text-white">
                                            {{ substr(data_get($deployment, 'commit'), 0, 7) }}
                                        </a>
                                        @if ($commitMessage)
                                            <a href="{{ $deploymentUrl }}" {!! $deploymentLinkAttributes !!}
                                                class="truncate text-neutral-500 dark:text-fg-faint group-hover:text-neutral-950 dark:group-hover:text-white"
                                                title="{{ $commitMessage }}">{{ $commitMessage }}</a>
                                        @endif
                                    </span>
                                @else
                                    <span class="text-neutral-400 dark:text-fg-faint group-hover:text-neutral-950 dark:group-hover:text-white">-</span>
                                @endif
                                @if ($embedded)
                                    <span class="mt-0.5 hidden truncate text-xs text-neutral-500 group-hover:text-neutral-950 dark:text-fg-faint dark:group-hover:text-white 2xl:block">
                                        {{ $sourceLabel }} · <span class="tabular-nums">{{ $duration }}</span> · {{ data_get($deployment, 'server_name') ?: data_get($application, 'destination.server.name', '-') }}
                                    </span>
                                @endif
                            </span>
                            @unless ($embedded)
                                <a href="{{ $deploymentUrl }}" {!! $deploymentLinkAttributes !!} title="{{ formatDateInServerTimezone(data_get($deployment, 'created_at'), data_get($application, 'destination.server')) }}">
                                    {{ \Carbon\Carbon::parse(data_get($deployment, 'created_at'))->diffForHumans() }}
                                </a>
                            @endunless
                            <a href="{{ $deploymentUrl }}" {!! $deploymentLinkAttributes !!} class="tabular-nums">{{ $duration }}</a>
                            <a href="{{ $deploymentUrl }}" {!! $deploymentLinkAttributes !!} class="truncate">
                                {{ data_get($deployment, 'server_name') ?: data_get($application, 'destination.server.name', '-') }}
                            </a>
                            @if ($embedded)
                                <a href="{{ $deploymentUrl }}" {!! $deploymentLinkAttributes !!}
                                    class="flex items-center justify-end gap-2 pr-1 tabular-nums"
                                    title="{{ $statusLabel }} · {{ formatDateInServerTimezone(data_get($deployment, 'created_at'), data_get($application, 'destination.server')) }}">
                                    <span>{{ \Carbon\Carbon::parse(data_get($deployment, 'created_at'))->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) }}</span>
                                    <x-loading compact x-cloak x-show="selecting === '{{ data_get($deployment, 'deployment_uuid') }}'"
                                        aria-label="Loading deployment logs..." />
                                    <span x-show="selecting !== '{{ data_get($deployment, 'deployment_uuid') }}'" @class([
                                        'size-2 shrink-0 rounded-full',
                                        'bg-emerald-500' => $statusType === 'success',
                                        'bg-warning motion-safe:animate-pulse' => $statusType === 'warning',
                                        'bg-red-500' => $statusType === 'error',
                                        'bg-neutral-400 dark:bg-neutral-500' => $statusType === 'neutral',
                                    ])></span>
                                </a>
                            @endif
                            <a href="{{ $deploymentUrl }}" {!! $deploymentLinkAttributes !!} class="deployment-row-link absolute inset-0"
                                aria-hidden="true" tabindex="-1"></a>
                        </div>
                        @endforeach

                        @if ($embedded)
                            <div wire:loading.flex wire:target="defaultTake" class="hidden justify-center py-6">
                                <x-loading aria-label="Loading deployments..." class="[&_.loading-indicator]:size-5" />
                            </div>
                        @endif
                    </div>

                    <x-table-pagination :from="$firstVisibleRow" :to="$lastVisibleRow" :total="$deployments_count"
                        :current-page="$currentPage" :last-page="$lastPage"
                        wire-target="goToPage,previousPage,nextPage" first-action="goToPage(1)"
                        previous-action="previousPage" next-action="nextPage"
                        last-action="goToPage({{ $lastPage }})">
                        @unless ($embedded)
                            <x-slot:pageSize>
                                <x-page-size-select model="defaultTake" livewire storage-key="coolify.page-size.deployments" />
                            </x-slot:pageSize>
                        @endunless
                    </x-table-pagination>
                </div>
            @else
                <x-empty size="sm" title="No deployments found"
                    :description="$hasActiveQuery
                        ? 'No deployments match the current search and filters.'
                        : 'Deploy the application to create its first deployment record.'"
                    icon-name="layers" />
            @endif
        </x-application.settings-section>
    </div>
        </div>
    </section>
</div>
