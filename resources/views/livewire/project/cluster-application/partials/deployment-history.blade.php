<x-application.settings-section id="cluster-application-deployments" title="Deployment history"
    helper="A deploy of a Git application builds the image first, then deploys it to each server. Open a deploy to see its build and deploy logs." flush>
    {{-- Same toolbar as the v4 deployment history: search, Filter (status and server), Sort. --}}
    <x-table.toolbar class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
        <x-slot:search>
            <x-table.search placeholder="Search deployments" loading-target="deploymentSearch"
                wire:model.live.debounce.300ms="deploymentSearch" />
        </x-slot:search>
        <x-table.filter :active-count="count($deploymentFilters)" reset-action="clearDeploymentFilters">
            @foreach (['status' => 'Status', 'server' => 'Server'] as $group => $groupLabel)
                @if (count($deploymentFilterOptions[$group] ?? []) > 0)
                    <span class="px-2 pb-1 pt-2 text-[10px] font-medium uppercase tracking-wider text-neutral-400 dark:text-fg-faint">{{ $groupLabel }}</span>
                    @foreach ($deploymentFilterOptions[$group] as $option)
                        @php
                            $selected = in_array($option['value'], $deploymentFilters, true);
                        @endphp
                        <button type="button" class="listbox-option" role="option"
                            aria-selected="{{ $selected ? 'true' : 'false' }}"
                            wire:click="toggleDeploymentFilter('{{ $option['value'] }}')">
                            <span class="truncate">{{ $option['label'] }}</span>
                            <span @class([
                                'flex size-4 shrink-0 items-center justify-center rounded-[5px] border',
                                'border-coollabs bg-coollabs text-white dark:border-brand dark:bg-brand dark:text-white' => $selected,
                                'border-neutral-300 bg-white dark:border-white/[0.14] dark:bg-white/[0.045]' => ! $selected,
                            ])>
                                @if ($selected)
                                    <svg class="size-3" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                        <path d="m2.25 6.15 2.35 2.3 5.15-5" stroke="currentColor" stroke-width="1.8"
                                            stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                @endif
                            </span>
                        </button>
                    @endforeach
                @endif
            @endforeach
        </x-table.filter>
        <x-table.sort>
            @foreach (['newest' => 'Newest first', 'oldest' => 'Oldest first'] as $sortValue => $sortLabel)
                <button type="button" class="listbox-option" role="option"
                    aria-selected="{{ $deploymentSort === $sortValue ? 'true' : 'false' }}"
                    wire:click="setDeploymentSort('{{ $sortValue }}')" @click="open = false">
                    <span>{{ $sortLabel }}</span>
                    @if ($deploymentSort === $sortValue)
                        <svg class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none">
                            <path d="m4.5 12.75 6 6 9-13.5" stroke="currentColor" stroke-width="2.5"
                                stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    @endif
                </button>
            @endforeach
        </x-table.sort>
    </x-table.toolbar>
    @if ($deploymentHistory['total'] > 0)
        <div class="data-table relative w-full transition-opacity"
            wire:loading.class="opacity-50 pointer-events-none" wire:target="goToPage,previousPage,nextPage,toggleDeploymentFilter,clearDeploymentFilters,setDeploymentSort,deploymentSearch">
            @php
                $embedded = filled($selectedDeploymentUuid ?? null);
            @endphp
            {{-- The compact v4 deployment table (project/application/deployment/index) on both pages; it folds into one column beside the log only. --}}
            {{-- Like v4: a click loads only the deployment log; modifier clicks still open the link. --}}
            <div @class(['deployment-table-scroll deployment-table-compact', 'deployment-table-embedded' => $embedded])
                @click="const link = $event.target.closest('a[data-select-deployment]');
                    if (!link || $event.button !== 0 || $event.metaKey || $event.ctrlKey || $event.shiftKey || $event.altKey) return;
                    $event.preventDefault();
                    $wire.selectDeployment(link.dataset.selectDeployment)">
                <div class="data-table-header deployment-table-grid rounded-none!">
                    <span>Source</span>
                    <span>Revision</span>
                    <span>Duration</span>
                    <span>Server</span>
                    <span class="text-right">Status</span>
                </div>

                @foreach ($deploymentHistory['rows'] as $deployment)
                    @php
                        $rowLink = new \Illuminate\View\ComponentAttributeBag(['href' => $deployment['href'], 'data-select-deployment' => $deployment['uuid']]);
                    @endphp
                    <div wire:key="cluster-app-{{ $deployment['kind'] }}-{{ $deployment['uuid'] }}" @class([
                        'data-table-row deployment-table-row deployment-table-grid group relative border-b border-neutral-200 text-[13px] text-neutral-600 hover:text-neutral-950 dark:border-white/[0.08] dark:text-fg-dim dark:hover:text-white',
                        'data-table-row-active' => ($selectedDeploymentUuid ?? null) === $deployment['uuid'],
                    ])>
                        <a {{ $rowLink }}>{{ $deployment['source'] }}</a>
                        <span class="min-w-0">
                            @if ($deployment['revision'])
                                <span class="flex min-w-0 items-center gap-2">
                                    <span class="shrink-0 font-mono text-xs text-neutral-950 dark:text-fg">{{ $deployment['revision'] }}</span>
                                    <span class="truncate text-neutral-500 group-hover:text-neutral-950 dark:text-fg-faint dark:group-hover:text-white"
                                        title="{{ $deployment['imageTitle'] }}">{{ $deployment['image'] }}</span>
                                    @if ($deployment['rollbackRevisionUuid'] ?? null)
                                        @can('update', $workload)
                                            <span class="relative z-10 ml-auto shrink-0">
                                                <x-modal-confirmation canGate="update" :canResource="$workload"
                                                    title="Roll back to this revision?" buttonTitle="Rollback"
                                                    warningMessage="The application restarts with the image of this deployment. A later deploy or rollback can change it again."
                                                    submitAction="rollback('{{ $deployment['rollbackRevisionUuid'] }}')"
                                                    :actions="[
                                                        'A new revision with the image of this deployment ('.$deployment['revision'].') deploys on every server of the application.',
                                                        'Environment variables, secrets, the start command, resource limits, volumes, and the health check keep their current values.',
                                                        'Volume data is not rolled back.',
                                                        'When the cluster registry no longer has the image, Coolify builds the same commit again before it deploys.',
                                                    ]"
                                                    :confirmWithText="false" :confirmWithPassword="false"
                                                    step2ButtonText="Roll back">
                                                    <x-slot:trigger>
                                                        <button type="button" class="icon-button" title="Roll back to this revision" aria-label="Roll back to this revision">
                                                            <x-reicon name="time-back" class="size-3.5" />
                                                        </button>
                                                    </x-slot:trigger>
                                                </x-modal-confirmation>
                                            </span>
                                        @endcan
                                    @endif
                                </span>
                            @else
                                <span class="text-neutral-400 dark:text-fg-faint">-</span>
                            @endif
                            @if ($embedded)
                                <span class="mt-0.5 hidden truncate text-xs text-neutral-500 group-hover:text-neutral-950 dark:text-fg-faint dark:group-hover:text-white 2xl:block">
                                    {{ $deployment['source'] }} · <span class="tabular-nums">{{ $deployment['duration'] }}</span> · {{ $deployment['node'] ?? '-' }}
                                </span>
                            @endif
                        </span>
                        <a {{ $rowLink }} class="tabular-nums">{{ $deployment['duration'] }}</a>
                        <a {{ $rowLink }} class="truncate">{{ $deployment['node'] ?? '-' }}</a>
                        <a {{ $rowLink }}
                            class="flex items-center justify-end gap-2 pr-1 tabular-nums"
                            title="{{ $deployment['status'] }} · {{ $deployment['createdAt'] }}">
                            <span>{{ $deployment['createdAt']->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) }}</span>
                            <span @class([
                                'size-2 shrink-0 rounded-full',
                                'bg-emerald-500' => $deployment['statusType'] === 'success',
                                'bg-warning motion-safe:animate-pulse' => $deployment['statusType'] === 'warning',
                                'bg-red-500' => $deployment['statusType'] === 'error',
                                'bg-neutral-400 dark:bg-neutral-500' => ! in_array($deployment['statusType'], ['success', 'warning', 'error'], true),
                            ])></span>
                        </a>
                        <a {{ $rowLink }} class="deployment-row-link absolute inset-0"
                            aria-hidden="true" tabindex="-1"></a>
                    </div>
                @endforeach
            </div>

            <x-table-pagination :from="$deploymentHistory['from']" :to="$deploymentHistory['to']"
                :total="$deploymentHistory['total']" :current-page="$deploymentHistory['currentPage']"
                :last-page="$deploymentHistory['lastPage']" wire-target="goToPage,previousPage,nextPage,toggleDeploymentFilter,clearDeploymentFilters,setDeploymentSort"
                first-action="goToPage(1)" previous-action="previousPage" next-action="nextPage"
                last-action="goToPage({{ $deploymentHistory['lastPage'] }})" />
        </div>
    @else
        @if (filled($deploymentSearch) || count($deploymentFilters) > 0)
            <x-empty size="sm" title="No deployments match" description="Change the search or the filters." icon-name="search" />
        @else
            <x-empty size="sm" title="No deployments found"
                description="Deploy the application to create its first deployment record." icon-name="layers" />
        @endif
    @endif
</x-application.settings-section>
