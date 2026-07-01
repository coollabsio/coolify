{{-- Reusable resource listing: card grid + compact list, toggled by Alpine `view`.
     Params: $heading (section title), $items (Alpine expression → array). --}}
<template x-if="{{ $items }}.length > 0">
    <h2 class="pt-4">{{ $heading }}</h2>
</template>

{{-- Card view --}}
<div x-show="view === 'card' && {{ $items }}.length > 0"
    class="grid grid-cols-1 gap-4 pt-4 lg:grid-cols-2 xl:grid-cols-3">
    <template x-for="item in {{ $items }}" :key="item.uuid">
        <span>
            <a class="h-24 coolbox group" :href="item.hrefLink" {{ wireNavigate() }}>
                <div class="flex flex-col w-full">
                    <div class="flex gap-2 px-4">
                        <div class="pb-2 truncate box-title" x-text="item.name"></div>
                        <div class="flex-1"></div>
                        <template x-if="item.status.startsWith('running')">
                            <div title="running" class="bg-success badge-dashboard"></div>
                        </template>
                        <template x-if="item.status.startsWith('exited')">
                            <div title="exited" class="bg-error badge-dashboard"></div>
                        </template>
                        <template x-if="item.status.startsWith('starting')">
                            <div title="starting" class="bg-warning badge-dashboard"></div>
                        </template>
                        <template x-if="item.status.startsWith('restarting')">
                            <div title="restarting" class="bg-warning badge-dashboard"></div>
                        </template>
                        <template x-if="item.status.startsWith('degraded')">
                            <div title="degraded" class="bg-warning badge-dashboard"></div>
                        </template>
                    </div>
                    <div class="max-w-full px-4 truncate box-description" x-text="item.description"></div>
                    <div class="max-w-full px-4 truncate box-description" x-text="item.fqdn"></div>
                    <div class="max-w-full px-4 pt-1 truncate box-description">Server: <span
                            x-text="item.destination?.server?.name || 'Unknown'"></span></div>
                    <template x-if="item.server_status == false">
                        <div class="px-4 text-xs font-bold text-error">Server is unreachable or misconfigured</div>
                    </template>
                </div>
            </a>
            <div class="flex flex-wrap gap-1 pt-1 dark:group-hover:text-white group-hover:text-black group min-h-6">
                <template x-for="tag in item.tags">
                    <a :href="`/tags/${tag.name}`" class="tag" x-text="tag.name"></a>
                </template>
                <a :href="`${item.hrefLink}/tags`" class="add-tag">Add tag</a>
            </div>
        </span>
    </template>
</div>

{{-- List view: compact single-line rows with a status dot, name, fqdn/description, and server --}}
<div x-show="view === 'list' && {{ $items }}.length > 0"
    class="mt-4 overflow-hidden border rounded-lg border-neutral-200 dark:border-coolgray-300 divide-y divide-neutral-200 dark:divide-coolgray-300">
    <template x-for="item in {{ $items }}" :key="item.uuid">
        <a class="flex items-center gap-3 px-4 py-2 transition-colors cursor-pointer group hover:bg-neutral-100 dark:hover:bg-coolgray-200"
            :href="item.hrefLink" {{ wireNavigate() }}>
            <span class="shrink-0 w-2 h-2 rounded-full" :class="statusColor(item.status)"
                :title="item.status || 'unknown'"></span>
            <span class="font-bold text-black truncate shrink-0 dark:text-white dark:group-hover:text-white"
                x-text="item.name"></span>
            <span class="flex-1 min-w-0 text-xs truncate text-neutral-500"
                x-text="item.fqdn || item.description || ''"></span>
            <template x-if="item.server_status == false">
                <span class="text-xs font-bold shrink-0 text-error">server unreachable</span>
            </template>
            <span class="hidden text-xs shrink-0 text-neutral-500 sm:block"
                x-text="item.destination?.server?.name || ''"></span>
        </a>
    </template>
</div>
