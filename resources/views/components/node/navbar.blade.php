@props(['node'])

@teleport('#server-topbar-context')
    <div data-testid="node-topbar-context" class="flex min-w-0 items-center gap-2 text-[13px]">
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
        <h1 class="min-w-0 truncate text-[24px]! leading-7! font-semibold! tracking-tight! text-black dark:text-fg">
            {{ $node->name }}
        </h1>
        <x-status-badge class="self-start" :status="$node->is_usable ? 'Ready' : 'Not ready'"
            :type="$node->is_usable ? 'success' : 'warning'" />
    </div>
</div>
