<x-application.settings-section id="cluster-application-cluster" title="Cluster"
    helper="The cluster that places this application on its servers.">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex min-w-0 items-center gap-3">
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                <x-reicon name="destinations" class="size-5" />
            </div>
            <h4 class="truncate text-sm font-semibold text-black dark:text-fg">{{ $node->cluster?->name ?? 'None' }}</h4>
        </div>
        @if ($node->cluster)
            <a href="{{ route('node-cluster.show', ['cluster_uuid' => $node->cluster->uuid]) }}" {{ wireNavigate() }}
                class="button shrink-0">Open cluster</a>
        @endif
    </div>
</x-application.settings-section>

<x-application.settings-section id="cluster-application-servers" :title="$servers->count() > 1 ? 'Servers' : 'Server'"
    helper="The servers that run this application.">
    <div class="flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.07]">
        @foreach ($servers as $server)
            <div wire:key="cluster-application-server-{{ $server->uuid }}"
                class="flex flex-col gap-4 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                        <x-reicon name="servers" class="size-5" />
                    </div>
                    <div class="min-w-0">
                        <h4 class="truncate text-sm font-semibold text-black dark:text-fg">{{ $server->name }}</h4>
                        @if ($server->ip)
                            <p class="mt-1 font-mono text-xs text-neutral-500 dark:text-fg-dim">{{ $server->ip }}</p>
                        @endif
                    </div>
                </div>
                <a href="{{ route('node.show', ['node_uuid' => $server->uuid]) }}" {{ wireNavigate() }}
                    class="button shrink-0">Open server</a>
            </div>
        @endforeach
    </div>
</x-application.settings-section>
