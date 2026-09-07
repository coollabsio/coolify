<div>
    <x-slot:title>Project Variables | Coolify</x-slot>

    <x-shared-variables.layout>
        <div class="w-full" x-data="{
            search: '',
            viewMode: localStorage.getItem('shared-variables-projects-view') || 'grid',
            matches(values) {
                const query = this.search.trim().toLowerCase();
                return !query || values.some(value => String(value || '').toLowerCase().includes(query));
            }
        }">
            @if ($projects->isEmpty())
                <x-empty title="No projects yet" description="Create a project before adding project-wide variables."
                    icon-name="projects" />
            @else
                <x-shared-variables.view-controls label="projects" storage-key="shared-variables-projects-view" />

                <div x-cloak x-show="viewMode === 'grid'" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($projects as $project)
                        <a x-show="matches(@js([$project->name, $project->description]))"
                            class="group flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                            href="{{ route('shared-variables.project.show', ['project_uuid' => $project->uuid]) }}" {{ wireNavigate() }}>
                            <div class="flex items-start gap-3">
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                    @if ($project->icon_path)
                                        <img src="{{ project_icon_url($project) }}"
                                            alt="{{ $project->name }} icon"
                                            class="h-full w-full rounded-lg object-cover">
                                    @else
                                        <x-reicon name="projects" class="size-4" />
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h2 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">{{ $project->name }}</h2>
                                    <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $project->description ?: 'No description' }}</p>
                                </div>
                            </div>
                            <div class="mt-auto pt-4 text-[11px] text-neutral-500 dark:text-fg-dim">
                                {{ $project->environment_variables()->count() }} {{ Str::plural('variable', $project->environment_variables()->count()) }}
                            </div>
                        </a>
                    @endforeach
                </div>

                <div x-show="viewMode === 'list'" class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
                    @foreach ($projects as $project)
                        <a x-show="matches(@js([$project->name, $project->description]))"
                            href="{{ route('shared-variables.project.show', ['project_uuid' => $project->uuid]) }}" {{ wireNavigate() }}
                            class="flex min-h-14 items-center gap-3 border-b border-neutral-200 px-4 py-2.5 last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                            @if ($project->icon_path)
                                <img src="{{ project_icon_url($project) }}"
                                    alt="{{ $project->name }} icon" class="size-4 shrink-0 rounded object-cover">
                            @else
                                <x-reicon name="projects" class="size-4 shrink-0 text-neutral-500 dark:text-fg-dim" />
                            @endif
                            <div class="min-w-0 flex-1"><div class="truncate text-[13px] font-medium">{{ $project->name }}</div><div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $project->description ?: 'No description' }}</div></div>
                            <span class="shrink-0 text-[11px] text-neutral-500 dark:text-fg-dim">{{ $project->environment_variables()->count() }} {{ Str::plural('variable', $project->environment_variables()->count()) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </x-shared-variables.layout>
</div>
