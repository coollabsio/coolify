<div>
    <x-slot:title>Environment Variables | Coolify</x-slot>

    <x-shared-variables.layout>
        <div class="w-full" x-data="{
            search: '',
            viewMode: localStorage.getItem('shared-variables-environments-view') || 'grid',
            matches(values) {
                const query = this.search.trim().toLowerCase();
                return !query || values.some(value => String(value || '').toLowerCase().includes(query));
            }
        }">
            @if ($projects->isEmpty())
                <x-empty title="No environments yet" description="Create a project environment before adding environment-wide variables." icon-name="layers" />
            @else
                <x-shared-variables.view-controls label="environments" storage-key="shared-variables-environments-view" />

                <div x-cloak x-show="viewMode === 'grid'" class="flex flex-col gap-6">
                    @foreach ($projects as $project)
                        <section x-show="matches(@js([
                            $project->name,
                            $project->description,
                            ...$project->environments->flatMap(fn ($environment) => [$environment->name, $environment->description])->all(),
                        ]))">
                            <div class="mb-3">
                                <h2 class="text-[14px]! leading-5! font-semibold! text-black dark:text-fg">{{ $project->name }}</h2>
                                <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">{{ $project->description ?: 'Project environments' }}</p>
                            </div>
                            @if ($project->environments->isEmpty())
                                <x-empty title="No environments in this project." size="sm" />
                            @else
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    @foreach ($project->environments as $environment)
                                        <a x-show="matches(@js([$environment->name, $environment->description, $project->name]))"
                                            class="group flex min-h-24 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                                            href="{{ route('shared-variables.environment.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}" {{ wireNavigate() }}>
                                            <div class="flex items-start gap-3">
                                                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim"><x-reicon name="layers" class="size-4" /></div>
                                                <div class="min-w-0 flex-1"><h3 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">{{ $environment->name }}</h3><p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $environment->description ?: 'No description' }}</p></div>
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    @endforeach
                </div>

                <div x-show="viewMode === 'list'" class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
                    @foreach ($projects as $project)
                        @foreach ($project->environments as $environment)
                            <a x-show="matches(@js([$environment->name, $environment->description, $project->name]))"
                                href="{{ route('shared-variables.environment.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}" {{ wireNavigate() }}
                                class="flex min-h-14 items-center gap-3 border-b border-neutral-200 px-4 py-2.5 last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                                <x-reicon name="layers" class="size-4 shrink-0 text-neutral-500 dark:text-fg-dim" />
                                <div class="min-w-0 flex-1"><div class="truncate text-[13px] font-medium">{{ $environment->name }}</div><div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $environment->description ?: 'No description' }}</div></div>
                                <span class="shrink-0 text-[11px] text-neutral-500 dark:text-fg-dim">{{ $project->name }}</span>
                            </a>
                        @endforeach
                    @endforeach
                </div>
            @endif
        </div>
    </x-shared-variables.layout>
</div>
