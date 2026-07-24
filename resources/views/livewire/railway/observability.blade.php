<div>
    <x-slot:title>{{ $project->name }} · Observability | Coolify</x-slot>

    <x-railway.project-chrome :project="$project" :environment="$environment"
        :projects="$allProjects" :environments="$allEnvironments" active="observability">

        <div class="h-full overflow-y-auto scrollbar">
            <div class="max-w-6xl mx-auto px-8 py-6">
                {{-- Toolbar --}}
                <div class="flex items-center justify-between mb-5">
                    <button type="button" class="rw-btn hover:rw-btn-hover">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        Last 1 hour
                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <button type="button" class="rw-btn hover:rw-btn-hover">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                        Add block
                    </button>
                </div>

                {{-- Usage / summary cards --}}
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                    <div class="rw-node !p-5">
                        <div class="flex items-center justify-between mb-4">
                            <div class="text-[14px] font-semibold text-rw-text">Environment usage</div>
                            <svg class="w-4 h-4 text-rw-subtle" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div class="rounded-lg border p-4 text-center" style="border-color: var(--color-rw-border); background: var(--color-rw-elevated);">
                                <div class="text-[11px] text-rw-subtle mb-1">Services</div>
                                <div class="text-[22px] font-semibold text-rw-text">{{ $total }}</div>
                            </div>
                            <div class="rounded-lg border p-4 text-center" style="border-color: var(--color-rw-border); background: var(--color-rw-elevated);">
                                <div class="text-[11px] text-rw-subtle mb-1">Online</div>
                                <div class="text-[22px] font-semibold text-rw-online">{{ $online }}<span class="text-rw-subtle text-[14px]">/{{ $total }}</span></div>
                            </div>
                            <div class="rounded-lg border p-4 text-center" style="border-color: var(--color-rw-border); background: var(--color-rw-elevated);">
                                <div class="text-[11px] text-rw-subtle mb-1">Environments</div>
                                <div class="text-[22px] font-semibold text-rw-text">{{ $allEnvironments->count() }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="rw-node !p-5">
                        <div class="flex items-center justify-between mb-4">
                            <div class="text-[14px] font-semibold text-rw-text">Error logs</div>
                            <svg class="w-4 h-4 text-rw-subtle" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>
                        </div>
                        <div class="flex flex-col items-center justify-center gap-2 py-8 text-center">
                            <svg class="w-9 h-9 text-rw-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>
                            <div class="text-[13px] text-rw-muted">No logs found for this filter</div>
                            <a href="{{ route('railway.logs', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}" wire:navigate class="text-[12px] text-rw-accent hover:underline">Open in Log Explorer →</a>
                        </div>
                    </div>
                </div>

                {{-- Services --}}
                <div class="rw-node !p-5">
                    <div class="text-[14px] font-semibold text-rw-text mb-4">Services</div>
                    @forelse ($services as $service)
                        <a href="{{ $service['href'] }}" wire:navigate class="flex items-center gap-3 py-2.5 border-b hover:bg-rw-hover -mx-2 px-2 rounded" style="border-color: var(--color-rw-border);">
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-md shrink-0" style="background: var(--color-rw-elevated); border: 1px solid var(--color-rw-border);">
                                <x-railway.resource-icon :type="$service['icon']" size="w-4 h-4" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="text-[13px] font-medium text-rw-text truncate">{{ $service['name'] }}</div>
                                @if ($service['subtitle'])
                                    <div class="text-[11px] text-rw-subtle truncate">{{ $service['subtitle'] }}</div>
                                @endif
                            </div>
                            <x-railway.status-dot :status="$service['status']" />
                        </a>
                    @empty
                        <div class="text-[13px] text-rw-subtle py-6 text-center">No services to observe yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </x-railway.project-chrome>
</div>
