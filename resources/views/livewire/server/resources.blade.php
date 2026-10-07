<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Server Resources | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="resources" />
        <div class="application-settings-form min-w-0 w-full">
        <x-application.settings-section id="server-resources-section" title="Resources"
            helper="Review Coolify-managed resources and other Docker containers running on this server."
            flush>
            <x-slot:actions>
                <div class="inline-flex w-fit rounded-[10px] bg-neutral-100 p-1 dark:bg-white/[0.05]">
                    <button type="button" wire:click="loadManagedContainers" wire:loading.attr="disabled" wire:target="loadManagedContainers,loadUnmanagedContainers"
                        class="inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-medium transition-colors disabled:cursor-wait {{ $activeTab === 'managed' ? 'bg-white text-neutral-950 shadow-sm dark:bg-warning/15 dark:text-warning' : 'text-neutral-500 hover:text-neutral-900 dark:text-fg-dim dark:hover:text-fg' }}">
                        <x-loading-on-button wire:loading wire:target="loadManagedContainers" />
                        Managed
                    </button>
                    <button type="button" wire:click="loadUnmanagedContainers" wire:loading.attr="disabled" wire:target="loadManagedContainers,loadUnmanagedContainers"
                        class="inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-medium transition-colors disabled:cursor-wait {{ $activeTab === 'unmanaged' ? 'bg-white text-neutral-950 shadow-sm dark:bg-warning/15 dark:text-warning' : 'text-neutral-500 hover:text-neutral-900 dark:text-fg-dim dark:hover:text-fg' }}">
                        <x-loading-on-button wire:loading wire:target="loadUnmanagedContainers" />
                        Unmanaged
                    </button>
                </div>
                <x-forms.button wire:click="refreshStatus">
                    <x-reicon name="refresh" class="size-3.5" />
                    Refresh
                </x-forms.button>
            </x-slot:actions>

            <div class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                <div class="relative w-full max-w-sm">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search resources by name"
                        aria-label="Search resources by name"
                        class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                    <button type="button" wire:click="$set('search', '')" @class([
                        'absolute top-1/2 right-2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg',
                        'hidden' => blank($search),
                    ]) aria-label="Clear search">
                        <x-reicon name="x" class="size-3" />
                    </button>
                </div>
            </div>

            <div class="relative">
            <div class="transition-all" wire:loading.class="pointer-events-none opacity-40 blur-[2px]"
                wire:loading.attr="inert" wire:target="search">
            @if ($activeTab === 'managed')
                @if ($resources->total() > 0)
                    <div class="data-table">
                        <div class="data-table-header server-resources-managed-table-grid">
                            <span>Name</span>
                            <span>Project</span>
                            <span>Environment</span>
                            <span>Type</span>
                            <span>Status</span>
                        </div>
                        @foreach ($resources as $resource)
                            @php($resourceStatus = (string) data_get($resource, 'status', 'unknown'))
                            <div wire:key="managed-{{ $resource->type() }}-{{ $resource->uuid }}"
                                class="data-table-row server-resources-managed-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                <div class="min-w-0">
                                    <a class="block max-w-full truncate text-[12px] font-medium text-neutral-950 hover:underline dark:text-fg"
                                        {{ wireNavigate() }} href="{{ $resource->link() }}">
                                        {{ $resource->name }}
                                    </a>
                                </div>
                                <div class="truncate text-[11px] text-neutral-600 dark:text-fg-dim">
                                    {{ data_get($resource->project(), 'name') }}
                                </div>
                                <div class="truncate text-[11px] text-neutral-600 dark:text-fg-dim">
                                    {{ data_get($resource, 'environment.name') }}
                                </div>
                                <div class="text-[11px] text-neutral-600 dark:text-fg-dim">
                                    {{ str($resource->type())->headline() }}
                                </div>
                                <div>
                                    @if (method_exists($resource, 'stoppedAfterRestartLimit') && $resource->stoppedAfterRestartLimit())
                                        <x-application.restart-limit-warning :application="$resource" />
                                    @else
                                        <x-status-badge :status="str($resourceStatus)->headline()"
                                            :type="str($resourceStatus)->contains('running')
                                                ? 'success'
                                                : (str($resourceStatus)->contains(['failed', 'exited']) ? 'error' : 'neutral')" />
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="p-6">
                        <x-empty size="sm" :title="trim($search) !== '' ? 'No matching resources' : 'No managed resources'"
                            :description="trim($search) !== '' ? 'Try another name or clear the search.' : 'Resources assigned to this server will appear here.'"
                            icon-name="projects" />
                    </div>
                @endif
            @else
                @if ($resources->total() > 0)
                    <div class="data-table">
                        <div class="data-table-header server-resources-unmanaged-table-grid">
                            <span>Name</span>
                            <span>Image</span>
                            <span>Status</span>
                            <span>Actions</span>
                        </div>
                        @foreach ($resources as $resource)
                            @php($containerState = (string) data_get($resource, 'State', 'unknown'))
                            <div wire:key="unmanaged-{{ data_get($resource, 'ID') }}"
                                class="data-table-row server-resources-unmanaged-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                <div class="min-w-0 truncate text-[12px] font-medium text-neutral-950 dark:text-fg">
                                    {{ data_get($resource, 'Names') }}
                                </div>
                                <div class="min-w-0 truncate font-mono text-[11px] text-neutral-600 dark:text-fg-dim">
                                    {{ data_get($resource, 'Image') }}
                                </div>
                                <div>
                                    <x-status-badge :status="str($containerState)->headline()"
                                        :type="$containerState === 'running'
                                            ? 'success'
                                            : ($containerState === 'exited' ? 'error' : 'warning')" />
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($containerState === 'running')
                                        <x-forms.button canGate="update" :canResource="$server"
                                            wire:click="restartUnmanaged('{{ data_get($resource, 'ID') }}')"
                                            wire:key="restart-{{ data_get($resource, 'ID') }}">
                                            Restart
                                        </x-forms.button>
                                        <x-forms.button canGate="update" :canResource="$server" isError
                                            wire:click="stopUnmanaged('{{ data_get($resource, 'ID') }}')"
                                            wire:key="stop-{{ data_get($resource, 'ID') }}">
                                            Stop
                                        </x-forms.button>
                                    @elseif ($containerState === 'exited')
                                        <x-forms.button canGate="update" :canResource="$server"
                                            wire:click="startUnmanaged('{{ data_get($resource, 'ID') }}')"
                                            wire:key="start-{{ data_get($resource, 'ID') }}">
                                            Start
                                        </x-forms.button>
                                    @elseif ($containerState === 'restarting')
                                        <x-forms.button canGate="update" :canResource="$server"
                                            wire:click="stopUnmanaged('{{ data_get($resource, 'ID') }}')"
                                            wire:key="stop-restarting-{{ data_get($resource, 'ID') }}">
                                            Stop
                                        </x-forms.button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="p-6">
                        <x-empty size="sm" :title="trim($search) !== '' ? 'No matching containers' : 'No unmanaged containers'"
                            :description="trim($search) !== '' ? 'Try another name or clear the search.' : 'All detected Docker containers are managed by Coolify.'"
                            icon-name="servers" />
                    </div>
                @endif
            @endif
            @if ($resources->total() > 0)
                <x-table-pagination :from="$resources->firstItem()" :to="$resources->lastItem()"
                    :total="$resources->total()" :current-page="$resources->currentPage()"
                    :last-page="$resources->lastPage()" wire-target="previousPage,nextPage,perPage"
                    previous-action="previousPage" next-action="nextPage">
                    <x-slot:pageSize>
                        <x-page-size-select model="perPage" livewire storage-key="coolify.page-size.server-resources" />
                    </x-slot:pageSize>
                </x-table-pagination>
            @endif
            </div>
                <x-table.loading target="search" text="Searching resources..." />
            </div>
        </x-application.settings-section>
        </div>
    </div>
</div>
