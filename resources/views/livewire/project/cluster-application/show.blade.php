<div>
    <x-slot:title>{{ $workload->name }} | Coolify</x-slot>
    <nav wire:poll.10000ms="refresh" class="w-full max-w-none pb-4 md:pb-6 lg:pb-0">
        <div class="mb-3 flex min-w-0 flex-col items-start gap-2 xl:hidden">
            <h1 class="min-w-0 max-w-full truncate text-[24px]! leading-7! font-semibold! tracking-tight!">{{ $workload->name }}</h1>
            <div class="flex items-center gap-2">
                <x-status-summary :status="strtolower($status)" />
                <x-cluster-applications.links :workload="$workload" compact />
            </div>
        </div>
        @can('update', $workload)
            <div class="mb-3 w-full xl:hidden">
                <x-cluster-applications.deployment-actions id="cluster-application-mobile-actions"
                    :status="$status" class="flex w-full" />
            </div>
        @endcan
        @teleport('#resource-action-hud-slot')
            <div class="hidden items-center gap-1 xl:flex">
                <x-cluster-applications.links :workload="$workload" />
                @can('update', $workload)
                    <x-cluster-applications.deployment-actions id="cluster-application-desktop-actions"
                        :status="$status" />
                @endcan
            </div>
        @endteleport
    </nav>

    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
            <aside class="application-settings-navigation min-w-0 xl:self-start">
                <nav aria-label="Cluster application sections"
                    class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                    <div class="nav-section hidden xl:block">Settings</div>
                    <a href="#general" class="menu-item menu-item-active"><x-reicon name="settings" class="menu-item-icon" /><span class="menu-item-label">General</span></a>
                    <a href="#deployments" class="menu-item"><x-reicon name="time-back" class="menu-item-icon" /><span class="menu-item-label">Deployment Logs</span></a>
                    <a href="{{ route('node.show', ['node_uuid' => $node->uuid]) }}" class="menu-item"><x-reicon name="servers" class="menu-item-icon" /><span class="menu-item-label">Node</span></a>
                </nav>
            </aside>

            <div class="flex min-w-0 flex-col gap-6">
        <x-application.settings-section id="general" title="Application details">
            <div class="grid gap-4 text-sm sm:grid-cols-2">
                <div><span class="text-neutral-500 dark:text-fg-dim">Image</span><p class="break-all font-mono text-xs">{{ $workload->revisions->first()?->image }}</p></div>
                <div><span class="text-neutral-500 dark:text-fg-dim">Cluster</span><p>{{ $node->cluster?->name ?? 'None' }}</p></div>
                <div><span class="text-neutral-500 dark:text-fg-dim">Node</span><p><a class="hover:underline" href="{{ route('node.show', ['node_uuid' => $node->uuid]) }}">{{ $node->name }}</a></p></div>
                <div><span class="text-neutral-500 dark:text-fg-dim">Internal DNS</span><p class="font-mono text-xs">{{ $workload->internal_dns_name ? $workload->internal_dns_name.'.default.coolify.internal' : 'Pending' }}</p></div>
            </div>
        </x-application.settings-section>

        <x-application.settings-section id="deployments" title="Deployment logs">
            <div class="flex flex-col gap-3">
                @forelse ($workload->operations as $operation)
                    <div wire:key="cluster-app-operation-{{ $operation->uuid }}" class="rounded-xl border border-neutral-200 px-4 py-3 text-sm dark:border-white/[0.08]">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span>{{ str($operation->command_type)->replace('.v1', '')->replace('.', ' ')->title() }}</span>
                            <x-status-badge :status="str($operation->status->value)->title()" :type="$operation->status->value === 'succeeded' ? 'success' : ($operation->status->value === 'failed' ? 'error' : 'warning')" />
                        </div>
                        @if ($operation->error)
                            <p class="mt-2 text-xs text-red-500">{{ $operation->error }}</p>
                        @endif
                    </div>
                @empty
                    <x-empty size="sm" title="No deployments yet" description="Deploy this application to create its first operation." icon-name="layers" />
                @endforelse
            </div>
        </x-application.settings-section>
            </div>
        </div>
    </section>
</div>
