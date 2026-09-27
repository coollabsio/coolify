@php
    $latestDeployment = $workload->operations->firstWhere('command_type', 'workload.deploy.v1');
@endphp

<x-application.settings-section id="cluster-application-overview" title="Overview"
    helper="Where this application runs and how other workloads in the cluster reach it.">
    <dl class="grid gap-x-6 gap-y-5 text-sm sm:grid-cols-2">
        <div class="min-w-0">
            <dt class="text-neutral-500 dark:text-fg-dim">Status</dt>
            <dd class="mt-1">
                <x-status-badge :status="$status" :type="$statusType" />
            </dd>
        </div>
        <div class="min-w-0">
            <dt class="text-neutral-500 dark:text-fg-dim">Image</dt>
            <dd class="mt-1 break-all font-mono text-xs">{{ $image ?? 'Unknown' }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="text-neutral-500 dark:text-fg-dim">Cluster</dt>
            <dd class="mt-1">
                @if ($node->cluster)
                    <a class="underline decoration-neutral-400 underline-offset-2 dark:decoration-neutral-600"
                        {{ wireNavigate() }}
                        href="{{ route('node-cluster.show', ['cluster_uuid' => $node->cluster->uuid]) }}">
                        {{ $node->cluster->name }}
                    </a>
                @else
                    <span class="text-neutral-500 dark:text-fg-dim">None</span>
                @endif
            </dd>
        </div>
        <div class="min-w-0">
            <dt class="text-neutral-500 dark:text-fg-dim">Node</dt>
            <dd class="mt-1">
                <a class="underline decoration-neutral-400 underline-offset-2 dark:decoration-neutral-600"
                    {{ wireNavigate() }}
                    href="{{ route('node.show', ['node_uuid' => $node->uuid]) }}">
                    {{ $node->name }}
                </a>
            </dd>
        </div>
        <div class="min-w-0">
            <dt class="text-neutral-500 dark:text-fg-dim">Internal hostname</dt>
            <dd class="mt-1 flex min-w-0 items-center gap-1.5">
                @if ($internalHostname)
                    <span class="truncate font-mono text-xs">{{ $internalHostname }}</span>
                    <x-copy-button :value="$internalHostname" label="Copy internal hostname" />
                @else
                    <span class="text-neutral-500 dark:text-fg-dim">Pending</span>
                @endif
            </dd>
        </div>
        <div class="min-w-0">
            <dt class="text-neutral-500 dark:text-fg-dim">Last deployment</dt>
            <dd class="mt-1">
                @if ($latestDeployment)
                    <span title="{{ $latestDeployment->created_at }}">
                        {{ $latestDeployment->created_at->diffForHumans() }}
                    </span>
                @else
                    <span class="text-neutral-500 dark:text-fg-dim">Never</span>
                @endif
            </dd>
        </div>
    </dl>
</x-application.settings-section>
