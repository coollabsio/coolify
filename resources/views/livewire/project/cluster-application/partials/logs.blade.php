{{-- Reuses the v4 runtime log viewer: one block per server, expanded when there is only one. --}}
<div class="flex w-full flex-col gap-4">
    @forelse ($servers as $server)
        @if ($servers->count() > 1)
            <div wire:key="cluster-application-logs-label-{{ $server->uuid }}" class="flex items-center gap-2 px-1">
                <x-reicon name="servers" class="size-3.5 text-neutral-400 dark:text-fg-faint" />
                <p class="text-xs font-medium text-neutral-500 dark:text-fg-dim">{{ $server->name }}</p>
            </div>
        @endif
        <livewire:project.shared.get-logs wire:key="cluster-application-logs-{{ $server->uuid }}"
            :server="$server" :resource="$workload" :container="'coolify-'.$workload->uuid.'-main'"
            :displayName="$workload->name" :expandByDefault="$servers->count() === 1" />
    @empty
        <x-empty size="lg" title="Runtime logs unavailable"
            description="This application is not placed on a server, so there are no runtime logs to show."
            icon-name="file-content" />
    @endforelse
</div>
