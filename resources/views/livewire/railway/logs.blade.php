<div>
    <x-slot:title>{{ $project->name }} · Logs | Coolify</x-slot>

    <x-railway.project-chrome :project="$project" :environment="$environment"
        :projects="$allProjects" :environments="$allEnvironments" active="logs">

        <div class="flex flex-col h-full">
            {{-- Filter bar --}}
            <div class="flex items-center gap-2 px-4 py-3 border-b" style="border-color: var(--color-rw-border);">
                <div class="flex items-center gap-2 flex-1 rounded-md border px-3 h-9" style="border-color: var(--color-rw-border); background: var(--color-rw-elevated);">
                    <svg class="w-4 h-4 text-rw-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="text" placeholder="Filter and search logs" class="flex-1 bg-transparent text-[13px] text-rw-text placeholder:text-rw-subtle focus:outline-none border-0 p-0" />
                    <kbd class="text-[11px] text-rw-subtle rounded border px-1.5 py-0.5" style="border-color: var(--color-rw-border);">/</kbd>
                </div>
                <button type="button" class="rw-btn hover:rw-btn-hover">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    Last 15 min
                </button>
                <button type="button" class="rw-icon-btn hover:rw-icon-btn-hover border" style="border-color: var(--color-rw-border);">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
                </button>
            </div>

            {{-- Empty state --}}
            <div class="flex flex-1 flex-col items-center justify-center gap-2 text-center">
                <svg class="w-12 h-12 text-rw-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>
                <div class="text-[15px] font-medium text-rw-text">No logs in this time range</div>
                <div class="text-[13px] text-rw-subtle">Logs will show up here as they are found.</div>
                <a href="{{ route('railway.canvas', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}" wire:navigate class="mt-2 text-[12px] text-rw-accent hover:underline">Open a service to stream its logs →</a>
            </div>
        </div>
    </x-railway.project-chrome>
</div>
