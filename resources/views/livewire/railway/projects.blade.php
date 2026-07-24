<div>
    <x-slot:title>Projects | Coolify</x-slot>
    <div class="flex w-full">
        <x-railway.sidebar active="projects" />

        <main class="flex-1 min-w-0 h-screen overflow-y-auto scrollbar">
            <div class="max-w-6xl mx-auto px-8 py-8">
                {{-- Header --}}
                <div class="flex items-start justify-between mb-8">
                    <div>
                        <h1 class="text-[28px] font-semibold text-rw-text">Projects</h1>
                        <div class="text-[13px] text-rw-subtle mt-1">{{ $groups->count() }} {{ $groups->count() === 1 ? 'project' : 'projects' }}, sorted by environment.</div>
                    </div>
                    <a href="{{ route('project.index') }}" wire:navigate class="rw-btn-primary hover:rw-btn-primary-hover">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                        New
                    </a>
                </div>

                @forelse ($groups as $project)
                    <section class="mb-9">
                        {{-- Project header --}}
                        <div class="flex items-center gap-2.5 mb-3">
                            <span class="inline-block w-5 h-5 rounded-full shrink-0" style="background: linear-gradient(135deg,#8b5cf6,#e5484d);"></span>
                            <h2 class="text-[16px] font-semibold text-rw-text truncate">{{ $project['name'] }}</h2>
                            <span class="rw-pill">{{ $project['environment_count'] }} {{ $project['environment_count'] === 1 ? 'environment' : 'environments' }}</span>
                            @if ($project['description'])
                                <span class="text-[12px] text-rw-subtle truncate">· {{ $project['description'] }}</span>
                            @endif
                            <div class="flex-1"></div>
                            <a href="{{ route('project.edit', ['project_uuid' => $project['uuid']]) }}" wire:navigate class="text-[12px] text-rw-subtle hover:text-rw-text">Settings</a>
                        </div>

                        {{-- Environment cards --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                            @forelse ($project['environments'] as $env)
                                <a href="{{ $env['url'] }}" wire:navigate class="rw-node hover:rw-node-hover !p-0 overflow-hidden group">
                                    <div class="flex items-center justify-between px-4 py-2.5">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <svg class="w-3.5 h-3.5 text-rw-subtle shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="6" rx="1"/><rect x="3" y="14" width="18" height="6" rx="1"/></svg>
                                            <span class="text-[13px] font-semibold text-rw-text truncate">{{ $env['name'] }}</span>
                                        </div>
                                    </div>
                                    <div class="relative h-28 mx-3 rounded-lg rw-canvas-grid border overflow-hidden" style="border-color: var(--color-rw-border);">
                                        <div class="absolute inset-0 flex items-center justify-center gap-2">
                                            @forelse ($env['glyphs'] as $glyph)
                                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg border shadow-sm" style="background: var(--color-rw-elevated); border-color: var(--color-rw-border-strong);">
                                                    <x-railway.resource-icon :type="$glyph" size="w-4 h-4" />
                                                </span>
                                            @empty
                                                <span class="text-[12px] text-rw-subtle">No services</span>
                                            @endforelse
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 px-4 py-3 text-[12px] text-rw-muted">
                                        <span class="rw-dot {{ $env['online'] > 0 ? 'rw-dot-online' : 'rw-dot-offline' }}"></span>
                                        <span class="truncate">{{ $env['online'] }}/{{ $env['total'] }} services online</span>
                                    </div>
                                </a>
                            @empty
                                <div class="rw-node items-center justify-center py-8 text-[12px] text-rw-subtle">No environments.</div>
                            @endforelse
                        </div>
                    </section>
                @empty
                    <div class="rw-node items-center justify-center py-16 text-center">
                        <div class="text-rw-muted text-[14px]">No projects yet.</div>
                        <a href="{{ route('project.index') }}" wire:navigate class="mt-3 rw-btn-primary hover:rw-btn-primary-hover">Create your first project</a>
                    </div>
                @endforelse
            </div>
        </main>
    </div>
</div>
