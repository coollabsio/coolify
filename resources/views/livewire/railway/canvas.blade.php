<div>
    <x-slot:title>{{ $project->name }} · {{ $environment->name }} | Coolify</x-slot>

    <x-railway.project-chrome :project="$project" :environment="$environment"
        :projects="$allProjects" :environments="$allEnvironments" active="architecture">

        <div class="relative w-full h-full rw-canvas-grid overflow-hidden"
            x-data="rwCanvas({ nodes: @js($nodes), edges: @js($edges), envUuid: '{{ $environment->uuid }}', nodeW: 240, nodeH: 82 })"
            x-init="init()"
            @mousedown="startPan($event)"
            @mousemove.window="onMove($event)"
            @mouseup.window="endInteraction()"
            @contextmenu.prevent="openContextMenu($event)"
            @wheel.prevent="onWheel($event)">

            {{-- Top-right actions --}}
            <div class="absolute top-4 right-4 z-20 flex items-center gap-2">
                <button type="button" @click="$dispatch('openRailwayAgent')" class="rw-btn hover:rw-btn-hover">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2M20 14h2M15 13v2M9 13v2"/></svg>
                    Ask AI
                </button>
                <button type="button" wire:click="$refresh" class="rw-btn hover:rw-btn-hover">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/></svg>
                    Sync
                </button>
                <a href="{{ route('project.resource.create', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}" wire:navigate
                    class="rw-btn-primary hover:rw-btn-primary-hover">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    Add
                </a>
            </div>

            {{-- Empty state --}}
            @if (count($nodes) === 0)
                <div class="absolute inset-0 flex flex-col items-center justify-center gap-3 z-10 pointer-events-none">
                    <div class="text-rw-muted text-[14px]">No services in this environment yet.</div>
                    <a href="{{ route('project.resource.create', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}" wire:navigate
                        class="rw-btn-primary hover:rw-btn-primary-hover pointer-events-auto">Add a service</a>
                </div>
            @endif

            {{-- Pan / zoom surface --}}
            <div class="absolute top-0 left-0 origin-top-left" x-bind:style="transformStyle()">
                {{-- Edges (single path — all connections concatenated) --}}
                <svg class="absolute top-0 left-0 overflow-visible pointer-events-none" width="1" height="1">
                    <path x-bind:d="edgesPath()" class="rw-edge" />
                </svg>

                {{-- Nodes (server-rendered content, Alpine-driven positions) --}}
                @foreach ($nodes as $node)
                    <div class="absolute rw-node hover:rw-node-hover"
                        style="width: 240px;"
                        x-bind:style="posStyle('{{ $node['id'] }}')"
                        x-bind:class="selected === '{{ $node['id'] }}' ? 'rw-node-selected' : ''"
                        @mousedown.stop="startDrag($event, '{{ $node['id'] }}')"
                        @click="openNode('{{ $node['id'] }}', '{{ $node['kind'] }}')">
                        {{-- Header --}}
                        <div class="flex items-center gap-2.5">
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-md shrink-0"
                                style="background: var(--color-rw-elevated); border: 1px solid var(--color-rw-border);">
                                <x-railway.resource-icon :type="$node['icon']" size="w-4 h-4" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="text-[13px] font-semibold text-rw-text truncate leading-tight">{{ $node['name'] }}</div>
                                @if ($node['subtitle'])
                                    <div class="text-[11px] text-rw-subtle truncate leading-tight">{{ $node['subtitle'] }}</div>
                                @endif
                            </div>
                        </div>
                        {{-- Footer --}}
                        <div class="mt-3">
                            <x-railway.status-dot :status="$node['status']" />
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Bottom-left control cluster --}}
            <div class="absolute bottom-4 left-4 z-20 flex flex-col gap-2">
                <button type="button" @click="fitView()" class="rw-icon-btn hover:rw-icon-btn-hover border" style="border-color: var(--color-rw-border); background: var(--color-rw-surface);" title="Grid">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="5" cy="5" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="19" cy="5" r="1"/><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="19" r="1"/><circle cx="12" cy="19" r="1"/><circle cx="19" cy="19" r="1"/></svg>
                </button>
                <div class="flex flex-col rounded-md border overflow-hidden" style="border-color: var(--color-rw-border); background: var(--color-rw-surface);">
                    <button type="button" @click="zoomBy(1.2)" class="rw-icon-btn hover:rw-icon-btn-hover rounded-none" title="Zoom in">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                    <button type="button" @click="zoomBy(1/1.2)" class="rw-icon-btn hover:rw-icon-btn-hover rounded-none" title="Zoom out">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/></svg>
                    </button>
                    <button type="button" @click="fitView()" class="rw-icon-btn hover:rw-icon-btn-hover rounded-none" title="Fit to view">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M8 21H5a2 2 0 0 1-2-2v-3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
                    </button>
                </div>
                <button type="button" @click="resetView()" class="rw-icon-btn hover:rw-icon-btn-hover border" style="border-color: var(--color-rw-border); background: var(--color-rw-surface);" title="Reset view">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-15-6.7L3 13"/></svg>
                </button>
            </div>

            {{-- Right-click: Add New Service --}}
            @php
                $createUrl = route('project.resource.create', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]);
                $addItems = [
                    ['label' => 'GitHub Repo', 'icon' => 'github', 'href' => $createUrl.'?type=private-gh-app'],
                    ['label' => 'Template', 'icon' => 'template', 'href' => $createUrl],
                    ['label' => 'Database', 'icon' => 'database', 'href' => $createUrl.'?type=postgresql'],
                    ['label' => 'Docker Image', 'icon' => 'docker', 'href' => $createUrl.'?type=docker-image'],
                    ['label' => 'Volume', 'icon' => 'volume', 'href' => $createUrl],
                    ['label' => 'Function', 'icon' => 'function', 'href' => $createUrl],
                    ['label' => 'Bucket', 'icon' => 'bucket', 'href' => $createUrl],
                    ['label' => 'Empty Service', 'icon' => 'terminal', 'href' => $createUrl.'?type=dockerfile'],
                ];
            @endphp
            <div x-show="ctxMenu.open" x-cloak x-transition.opacity.duration.100ms
                @click.outside="closeContextMenu()"
                class="absolute z-50 w-60 rw-menu"
                x-bind:style="`left: ${ctxMenu.x}px; top: ${ctxMenu.y}px;`">
                <div class="px-2.5 py-2 text-[12px] font-medium text-rw-subtle">Add New Service</div>
                @foreach ($addItems as $it)
                    <a href="{{ $it['href'] }}" wire:navigate @click="closeContextMenu()"
                        class="rw-menu-item hover:rw-menu-item-hover !py-2 gap-3">
                        <span class="inline-flex items-center justify-center w-5 h-5 text-rw-muted shrink-0">
                            @switch($it['icon'])
                                @case('github')
                                    <svg class="w-[18px] h-[18px]" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/></svg>
                                    @break
                                @case('template')
                                    <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/></svg>
                                    @break
                                @case('database')
                                    <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.66 3.58 3 8 3s8-1.34 8-3V5"/><path d="M4 12c0 1.66 3.58 3 8 3s8-1.34 8-3"/></svg>
                                    @break
                                @case('docker')
                                    <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>
                                    @break
                                @case('volume')
                                    <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="8" rx="2"/><path d="M7 12h.01"/></svg>
                                    @break
                                @case('function')
                                    <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M14 8h-2.5A1.5 1.5 0 0 0 10 9.5V21M9 13h4"/></svg>
                                    @break
                                @case('bucket')
                                    <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 7h14l-1.2 12.1a2 2 0 0 1-2 1.9H8.2a2 2 0 0 1-2-1.9L5 7Z"/><path d="M4 7h16M9 4h6"/></svg>
                                    @break
                                @default
                                    <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 4 4-4 4"/><path d="M12 16h6"/></svg>
                            @endswitch
                        </span>
                        <span class="text-[14px] text-rw-text">{{ $it['label'] }}</span>
                    </a>
                @endforeach
            </div>

            {{-- Service detail slide-over --}}
            <livewire:railway.service-panel :environment="$environment" :project="$project" />

            {{-- AI assistant panel --}}
            <livewire:railway.agent :environment="$environment" :project="$project" />
        </div>
    </x-railway.project-chrome>

</div>
