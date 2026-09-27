<x-application.settings-section id="cluster-application-deployments" title="Deployment history"
    helper="The 20 most recent deployments and lifecycle actions for this application, newest first." flush>
    @if (count($deployments) > 0)
        <ul class="divide-y divide-neutral-200 dark:divide-white/[0.08]">
            @foreach ($deployments as $deployment)
                <li wire:key="cluster-app-operation-{{ $deployment['uuid'] }}" class="flex flex-col gap-2 px-4 py-3 text-sm">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-status-badge :status="$deployment['status']" :type="$deployment['statusType']" />
                            <span class="font-medium text-neutral-950 dark:text-fg">{{ $deployment['label'] }}</span>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-neutral-500 dark:text-fg-dim">
                            @if ($deployment['duration'])
                                <span class="tabular-nums">{{ $deployment['duration'] }}</span>
                            @endif
                            <span title="{{ $deployment['created_at'] }}">
                                {{ $deployment['created_at']?->diffForHumans() }}
                            </span>
                        </div>
                    </div>
                    @if ($deployment['error'])
                        <div class="whitespace-pre-wrap break-words rounded-lg border border-red-500/20 bg-red-500/5 px-3 py-2 font-mono text-xs text-red-600 dark:text-red-400">
                            {{ $deployment['error'] }}
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <x-empty size="sm" title="No deployments yet"
            description="Deploy this application to create its first deployment record." icon-name="layers" />
    @endif
</x-application.settings-section>
