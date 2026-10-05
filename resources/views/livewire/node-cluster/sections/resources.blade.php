@php
    $resourceGridClasses = 'grid grid-cols-[minmax(0,1fr)_auto] gap-3 px-4 md:min-w-[760px] md:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.2fr)_8rem]';
@endphp

<x-application.settings-section id="node-cluster-resources-section" title="Resources" flush
    helper="Applications that run on the servers of this cluster.">
    @if ($resourcesTotal > 0)
        <div class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
            <div class="relative w-full max-w-sm">
                <x-reicon name="search"
                    class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                <input wire:model.live.debounce.300ms="resourceSearch" type="search"
                    placeholder="Search by name, project, server, or domain" aria-label="Search resources"
                    class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-3! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
            </div>
        </div>
    @endif

    @if ($resources !== [])
        <div class="overflow-x-auto">
            <div
                class="{{ $resourceGridClasses }} border-b border-neutral-200 bg-neutral-50 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                <div>Name</div>
                <div class="hidden md:block">Project</div>
                <div class="hidden md:block">Servers</div>
                <div class="hidden md:block">Domains</div>
                <div>Status</div>
            </div>
            @foreach ($resources as $resource)
                <div wire:key="cluster-resource-{{ $resource['uuid'] }}"
                    class="{{ $resourceGridClasses }} min-h-14 items-center border-b border-neutral-200 py-2.5 text-[12px] last:border-b-0 dark:border-white/[0.07]">
                    <div class="min-w-0">
                        @if ($resource['href'])
                            <a href="{{ $resource['href'] }}" {{ wireNavigate() }}
                                class="block truncate text-[13px] font-semibold text-black hover:underline dark:text-fg">{{ $resource['name'] }}</a>
                        @else
                            <span class="block truncate text-[13px] font-semibold text-black dark:text-fg">{{ $resource['name'] }}</span>
                        @endif
                        <p class="truncate font-mono text-[11px] text-neutral-500 dark:text-fg-faint">{{ $resource['image'] ?? '-' }}</p>
                    </div>
                    <div class="hidden min-w-0 md:block">
                        <p class="truncate text-neutral-700 dark:text-fg">{{ $resource['project'] ?? '-' }}</p>
                        <p class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $resource['environment'] }}</p>
                    </div>
                    <div class="hidden min-w-0 text-neutral-600 md:block dark:text-fg-dim">
                        @forelse ($resource['servers'] as $serverName)
                            <p class="truncate">{{ $serverName }}</p>
                        @empty
                            <p>-</p>
                        @endforelse
                    </div>
                    <div class="hidden min-w-0 md:block">
                        @forelse ($resource['domains'] as $domain)
                            <a href="http://{{ $domain }}" target="_blank" rel="noopener noreferrer"
                                class="block truncate text-neutral-600 hover:underline dark:text-fg-dim">{{ $domain }}</a>
                        @empty
                            <p class="text-neutral-400 dark:text-fg-faint">-</p>
                        @endforelse
                    </div>
                    <div>
                        <x-status-badge :status="$resource['status']" :type="$resource['statusType']" />
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="p-6">
            <x-empty size="sm" :title="$resourcesTotal > 0 ? 'No matching resources' : 'No resources yet'"
                :description="$resourcesTotal > 0
                    ? 'Try another search or clear it.'
                    : 'Applications appear here after you deploy them to this cluster.'"
                icon-name="projects" />
        </div>
    @endif
</x-application.settings-section>
