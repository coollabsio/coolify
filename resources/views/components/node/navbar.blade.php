@props(['node'])

@teleport('#server-topbar-context')
    <div data-testid="node-topbar-context" class="flex min-w-0 items-center gap-1 text-[13px]">
        @if ($node->cluster)
            <span class="shrink-0 px-0.5 text-neutral-300 dark:text-fg-faint">/</span>
            <a href="{{ route('node-cluster.show', ['cluster_uuid' => $node->cluster->uuid]) }}" {{ wireNavigate() }}
                class="min-w-0 max-w-48 truncate rounded-md px-2 py-1 text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.05] dark:hover:text-fg">
                {{ $node->cluster->name }}
            </a>
        @endif
        <span class="shrink-0 px-0.5 text-neutral-300 dark:text-fg-faint">/</span>
        <span class="min-w-0 truncate px-2 font-semibold text-black dark:text-fg">
            {{ $node->name }}
        </span>
        <x-status-badge :status="$node->is_usable ? 'Ready' : 'Not ready'"
            :type="$node->is_usable ? 'success' : 'warning'" />
    </div>
@endteleport

<div class="mb-3 w-full lg:hidden">
    <div class="flex min-w-0 flex-col gap-2">
        @if ($node->cluster)
            <a href="{{ route('node-cluster.show', ['cluster_uuid' => $node->cluster->uuid]) }}" {{ wireNavigate() }}
                class="self-start text-[12px] text-neutral-500 hover:text-black dark:text-fg-dim dark:hover:text-fg">
                {{ $node->cluster->name }}
            </a>
        @endif
        <h1 class="min-w-0 truncate text-[24px]! leading-7! font-semibold! tracking-tight! text-black dark:text-fg">
            {{ $node->name }}
        </h1>
        <x-status-badge class="self-start" :status="$node->is_usable ? 'Ready' : 'Not ready'"
            :type="$node->is_usable ? 'success' : 'warning'" />
    </div>
</div>
