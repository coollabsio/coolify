<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Server Resources | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div class="application-settings-form w-full">
        <x-application.settings-section id="server-resources-section" title="Resources"
            helper="Review Coolify-managed resources and other Docker containers running on this server."
            flush>
            <x-slot:actions>
                <div class="inline-flex w-fit rounded-[10px] bg-neutral-100 p-1 dark:bg-white/[0.05]">
                    <button type="button" wire:click="loadManagedContainers"
                        class="rounded-md px-3 py-1 text-xs font-medium transition-colors {{ $activeTab === 'managed' ? 'bg-white text-neutral-950 shadow-sm dark:bg-warning/15 dark:text-warning' : 'text-neutral-500 hover:text-neutral-900 dark:text-fg-dim dark:hover:text-fg' }}">
                        Managed
                    </button>
                    <button type="button" wire:click="loadUnmanagedContainers"
                        class="rounded-md px-3 py-1 text-xs font-medium transition-colors {{ $activeTab === 'unmanaged' ? 'bg-white text-neutral-950 shadow-sm dark:bg-warning/15 dark:text-warning' : 'text-neutral-500 hover:text-neutral-900 dark:text-fg-dim dark:hover:text-fg' }}">
                        Unmanaged
                    </button>
                </div>
                <x-forms.button wire:click="refreshStatus">
                    <x-reicon name="refresh" class="size-3.5" />
                    Refresh
                </x-forms.button>
            </x-slot:actions>

            @if ($activeTab === 'managed')
                @php($managedResources = $server->definedResources()->sortBy('name', SORT_NATURAL))
                @if ($managedResources->count() > 0)
                    <div class="data-table">
                        <div class="data-table-header server-resources-managed-table-grid">
                            <span>Name</span>
                            <span>Project</span>
                            <span>Environment</span>
                            <span>Type</span>
                            <span>Status</span>
                        </div>
                        @foreach ($managedResources as $resource)
                            @php($resourceStatus = (string) data_get($resource, 'status', 'unknown'))
                            <div
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
                                    <x-status-badge :status="str($resourceStatus)->headline()"
                                        :type="str($resourceStatus)->contains('running')
                                            ? 'success'
                                            : (str($resourceStatus)->contains(['failed', 'exited']) ? 'error' : 'neutral')" />
                                </div>
                            </div>
                        @endforeach
                        <div
                            class="flex min-h-11 items-center border-t border-neutral-200 px-4 text-[11px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                            {{ $managedResources->count() }}
                            {{ Str::plural('managed resource', $managedResources->count()) }}
                        </div>
                    </div>
                @else
                    <div class="p-6">
                        <x-empty size="sm" title="No managed resources"
                            description="Resources assigned to this server will appear here.">
                            <x-slot:icon>
                                <x-reicon name="projects" class="size-8" />
                            </x-slot:icon>
                        </x-empty>
                    </div>
                @endif
            @else
                @if (count($unmanagedContainers) > 0)
                    @php($sortedUnmanagedContainers = collect($unmanagedContainers)->sortBy('name', SORT_NATURAL))
                    <div class="data-table">
                        <div class="data-table-header server-resources-unmanaged-table-grid">
                            <span>Name</span>
                            <span>Image</span>
                            <span>Status</span>
                            <span>Actions</span>
                        </div>
                        @foreach ($sortedUnmanagedContainers as $resource)
                            @php($containerState = (string) data_get($resource, 'State', 'unknown'))
                            <div
                                class="data-table-row server-resources-unmanaged-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                <div class="truncate font-mono text-[12px] text-neutral-950 dark:text-fg">
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
                        <div
                            class="flex min-h-11 items-center border-t border-neutral-200 px-4 text-[11px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                            {{ $sortedUnmanagedContainers->count() }}
                            {{ Str::plural('unmanaged container', $sortedUnmanagedContainers->count()) }}
                        </div>
                    </div>
                @else
                    <div class="p-6">
                        <x-empty size="sm" title="No unmanaged containers"
                            description="All detected Docker containers are managed by Coolify.">
                            <x-slot:icon>
                                <x-reicon name="servers" class="size-8" />
                            </x-slot:icon>
                        </x-empty>
                    </div>
                @endif
            @endif
        </x-application.settings-section>
    </div>
</div>
