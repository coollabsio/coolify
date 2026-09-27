<x-application.settings-section id="cluster-application-deployments" title="Deployment history"
    helper="Open a deployment to inspect its logs." flush>
    @if ($deploymentHistory['total'] > 0)
        <div class="data-table relative w-full transition-opacity"
            wire:loading.class="opacity-50 pointer-events-none" wire:target="goToPage,previousPage,nextPage">
            <div class="deployment-table-scroll">
                <div class="data-table-header deployment-table-grid rounded-none!">
                    <span>Status</span>
                    <span>Source</span>
                    <span>Revision</span>
                    <span>Started</span>
                    <span>Duration</span>
                    <span>Node</span>
                </div>

                @foreach ($deploymentHistory['rows'] as $deployment)
                    <div wire:key="cluster-app-deployment-{{ $deployment['uuid'] }}" @class([
                        'data-table-row deployment-table-grid border-b border-neutral-200 text-[13px] text-neutral-600 dark:border-white/[0.08] dark:text-fg-dim',
                        'data-table-row-active' => ($selectedDeploymentUuid ?? null) === $deployment['uuid'],
                    ])>
                        <a href="{{ $deployment['href'] }}" {{ wireNavigate() }}>
                            <x-status-badge :status="$deployment['status']" :type="$deployment['statusType']" />
                        </a>
                        <a href="{{ $deployment['href'] }}" {{ wireNavigate() }}>{{ $deployment['source'] }}</a>
                        <a href="{{ $deployment['href'] }}" {{ wireNavigate() }} class="flex min-w-0 items-center gap-2">
                            @if ($deployment['revision'])
                                <span class="shrink-0 font-mono text-xs text-neutral-950 dark:text-fg">{{ $deployment['revision'] }}</span>
                                <span class="truncate text-neutral-500 dark:text-fg-faint" title="{{ $deployment['image'] }}">{{ $deployment['image'] }}</span>
                            @else
                                <span class="text-neutral-400 dark:text-fg-faint">-</span>
                            @endif
                        </a>
                        <a href="{{ $deployment['href'] }}" {{ wireNavigate() }} title="{{ $deployment['createdAt'] }}">
                            {{ $deployment['createdAt']->diffForHumans() }}
                        </a>
                        <a href="{{ $deployment['href'] }}" {{ wireNavigate() }} class="tabular-nums">{{ $deployment['duration'] }}</a>
                        <a href="{{ $deployment['href'] }}" {{ wireNavigate() }} class="truncate">{{ $deployment['node'] ?? '-' }}</a>
                    </div>
                @endforeach
            </div>

            <x-table-pagination :from="$deploymentHistory['from']" :to="$deploymentHistory['to']"
                :total="$deploymentHistory['total']" :current-page="$deploymentHistory['currentPage']"
                :last-page="$deploymentHistory['lastPage']" wire-target="goToPage,previousPage,nextPage"
                first-action="goToPage(1)" previous-action="previousPage" next-action="nextPage"
                last-action="goToPage({{ $deploymentHistory['lastPage'] }})" />
        </div>
    @else
        <x-empty size="sm" title="No deployments found"
            description="Deploy the application to create its first deployment record." icon-name="layers" />
    @endif
</x-application.settings-section>
