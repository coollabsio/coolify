<x-application.settings-section id="cluster-stack-deployments" title="Deployment history" flush
    helper="The last 20 deploys of this stack.">
    <div class="overflow-x-auto rounded-lg">
        @if ($deployments === [])
            <div class="p-4">
                <x-empty title="No deployments" description="Deploy the stack to see its deployments here." icon-name="time-back" />
            </div>
        @else
            <div class="grid min-w-[44rem] grid-cols-[8rem_minmax(10rem,1fr)_7rem_8rem] gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                <div>Source</div>
                <div>Deployment</div>
                <div>Duration</div>
                <div>Status</div>
            </div>
        @endif
        @foreach ($deployments as $deployment)
            <div wire:key="cluster-stack-deployment-{{ $deployment['uuid'] }}"
                class="grid min-h-12 min-w-[44rem] grid-cols-[8rem_minmax(10rem,1fr)_7rem_8rem] items-center gap-3 border-b border-neutral-200 px-4 py-2.5 text-[13px] last:border-b-0 dark:border-white/[0.07]">
                <div class="truncate text-neutral-600 dark:text-fg-dim">{{ $deployment['by'] ?? 'Manual' }}</div>
                <div class="min-w-0">
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="font-medium text-black dark:text-fg">Deploy</span>
                        @if ($deployment['commit'])
                            <span class="font-mono text-xs text-neutral-500 dark:text-fg-dim">{{ $deployment['commit'] }}</span>
                        @endif
                        <span class="truncate text-xs text-neutral-500 dark:text-fg-faint">{{ $deployment['createdAt']?->diffForHumans() }}</span>
                    </div>
                    @if (filled($deployment['error']))
                        <p class="mt-1 line-clamp-2 text-xs text-red-500 dark:text-red-400" title="{{ $deployment['error'] }}">{{ $deployment['error'] }}</p>
                    @endif
                </div>
                <div class="text-neutral-500 dark:text-fg-dim">{{ $deployment['duration'] ?? '-' }}</div>
                <div><x-status-badge :status="ucfirst($deployment['status'])" :type="$deployment['statusType']" /></div>
            </div>
        @endforeach
    </div>
</x-application.settings-section>
