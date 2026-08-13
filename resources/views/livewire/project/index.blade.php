<div class="application-settings-form w-full">
    <x-slot:title>
        Projects | Coolify
    </x-slot>

    <div x-data="projectsIndex()" class="w-full">
        <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="min-w-0 truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Projects</h1>
            @can('createAnyResource')
                <div class="w-fit shrink-0">
                    <x-modal-input title="New Project">
                        <x-slot:content>
                            <button type="button"
                                class="button button-highlighted">
                                <x-reicon name="plus" class="size-3.5" />
                                New project
                            </button>
                        </x-slot:content>
                        <livewire:project.add-empty />
                    </x-modal-input>
                </div>
            @endcan
        </header>

        @if ($projects->isEmpty())
            <x-empty title="No projects yet"
                description="Create a project to organize your environments and resources."
                icon-name="projects">
                <x-slot:contents>
                    <a class="text-[12px] font-medium text-coollabs hover:underline dark:text-warning"
                        href="{{ route('onboarding') }}" {{ wireNavigate() }}>
                        Open onboarding
                    </a>
                </x-slot:contents>
            </x-empty>
        @else
            <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full sm:max-w-sm">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input x-model.debounce.150ms="search" x-on:input="page = 1" type="search"
                        placeholder="Search projects"
                        class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                    <button x-cloak x-show="search" x-on:click="search = ''; page = 1" type="button"
                        class="absolute top-1/2 right-2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
                        aria-label="Clear search">
                        <span class="text-sm leading-none">×</span>
                    </button>
                </div>

                <div class="flex items-center gap-2">
                    <div class="relative" x-on:click.outside="sortOpen = false">
                        <button type="button" class="button" x-on:click="sortOpen = !sortOpen">
                            <svg class="size-3.5 opacity-65" viewBox="0 0 24 24" fill="none"
                                aria-hidden="true">
                                <path d="M8 5v14m0 0-3-3m3 3 3-3M16 19V5m0 0-3 3m3-3 3 3"
                                    stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                            Sort
                        </button>
                        <div x-cloak x-show="sortOpen" x-transition.origin.top.right
                            class="absolute top-9 right-0 z-50 w-52 rounded-lg border border-neutral-200 bg-white p-1 shadow-modal dark:border-white/[0.1] dark:bg-raised">
                            <template x-for="option in sortOptions" :key="option.value">
                                <button type="button"
                                    class="flex h-9 w-full items-center rounded-md px-2 text-left text-[12px] text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                    x-on:click="sortBy = option.value; sortOpen = false; page = 1">
                                    <span class="flex-1" x-text="option.label"></span>
                                    <svg x-show="sortBy === option.value" class="size-3.5 text-warning"
                                        viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                        <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor"
                                            stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div
                        class="flex h-9 items-center rounded-lg border border-neutral-200 bg-white p-0.5 dark:border-white/[0.08] dark:bg-white/[0.06]">
                        <button type="button" x-on:click="setViewMode('table')"
                            class="flex size-7.5 items-center justify-center rounded-md transition-colors"
                            :class="viewMode === 'table'
                                ?
                                'control-selected' :
                                'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'"
                            aria-label="Table view" title="Table view">
                            <x-reicon name="unordered-list" class="size-3.5" />
                        </button>
                        <button type="button" x-on:click="setViewMode('grid')"
                            class="flex size-7.5 items-center justify-center rounded-md transition-colors"
                            :class="viewMode === 'grid'
                                ?
                                'control-selected' :
                                'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'"
                            aria-label="Grid view" title="Grid view">
                            <x-reicon name="grid" class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>

            <div x-cloak x-show="viewMode === 'grid'">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <template x-for="project in paginatedProjects" :key="project.uuid">
                        <article
                            class="group relative flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                            <a :href="project.href" {{ wireNavigate() }} class="absolute inset-0 rounded-xl"
                                :aria-label="`Open ${project.name}`"></a>
                            <div class="flex items-start gap-3">
                                <div
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                    <template x-if="project.iconUrl">
                                        <img :src="project.iconUrl" :alt="`${project.name} icon`" class="h-full w-full rounded-lg object-cover">
                                    </template>
                                    <template x-if="!project.iconUrl">
                                        <x-reicon name="projects" class="size-4" />
                                    </template>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h2
                                        class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg"
                                        x-text="project.name"></h2>
                                    <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint"
                                        x-text="project.description || 'No description'"></p>
                                </div>
                            </div>

                            <div class="mt-auto flex items-center justify-between gap-3 pt-4">
                                <p class="min-w-0 truncate text-[11px] text-neutral-500 dark:text-fg-dim">
                                    <span
                                        x-text="`${project.environmentCount} ${project.environmentCount === 1 ? 'env' : 'envs'}`"></span>
                                    <span class="px-1 text-neutral-300 dark:text-white/15">·</span>
                                    <span
                                        x-text="`${project.resourceCount} ${project.resourceCount === 1 ? 'resource' : 'resources'}`"></span>
                                </p>

                                <div class="relative z-10 flex shrink-0 items-center gap-0.5">
                                    <a x-show="project.addResourceHref" :href="project.addResourceHref"
                                        {{ wireNavigate() }}
                                        class="flex size-7.5 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                        title="Add resource" :aria-label="`Add resource to ${project.name}`">
                                        <x-reicon name="plus" class="size-3" />
                                    </a>
                                    <a x-show="project.settingsHref" :href="project.settingsHref" {{ wireNavigate() }}
                                        class="flex size-7.5 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                        title="Project settings" :aria-label="`Open settings for ${project.name}`">
                                        <x-reicon name="settings" class="size-3" />
                                    </a>
                                </div>
                            </div>
                        </article>
                    </template>
                </div>

                <footer x-show="totalPages > 1"
                    class="mt-3 flex min-h-11 items-center justify-between rounded-xl border border-neutral-200 bg-white px-4 text-[11px] text-neutral-500 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                    <span x-text="`${rangeStart}-${rangeEnd} of ${filteredProjects.length}`"></span>
                    <div class="flex items-center gap-1">
                        <button type="button" x-on:click="previousPage" :disabled="page === 1"
                            class="flex size-7 items-center justify-center rounded-md border border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black disabled:pointer-events-none disabled:opacity-35 dark:border-white/[0.08] dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                            aria-label="Previous page">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none"
                                aria-hidden="true">
                                <path d="m15 5-7 7 7 7" stroke="currentColor" stroke-width="1.7"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>
                        <button type="button" x-on:click="nextPage" :disabled="page >= totalPages"
                            class="flex size-7 items-center justify-center rounded-md border border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black disabled:pointer-events-none disabled:opacity-35 dark:border-white/[0.08] dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                            aria-label="Next page">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none"
                                aria-hidden="true">
                                <path d="m9 5 7 7-7 7" stroke="currentColor" stroke-width="1.7"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>
                    </div>
                </footer>
            </div>

            <div x-show="viewMode === 'table'"
                class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
                <div
                    class="projects-table-grid border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                    <div>Project</div>
                    <div>Environments</div>
                    <div>Resources</div>
                    <div class="project-description">Description</div>
                    <div></div>
                </div>

                <template x-for="project in paginatedProjects" :key="project.uuid">
                    <div
                        class="projects-table-grid group min-h-14 items-center border-b border-neutral-200 px-4 py-2.5 transition-colors last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-dim">
                                <template x-if="project.iconUrl">
                                    <img :src="project.iconUrl" :alt="`${project.name} icon`" class="h-full w-full rounded-lg object-cover">
                                </template>
                                <template x-if="!project.iconUrl">
                                    <x-reicon name="projects" class="size-4" />
                                </template>
                            </div>
                            <a :href="project.href" {{ wireNavigate() }}
                                class="truncate text-[13px] font-semibold text-black hover:underline dark:text-fg"
                                x-text="project.name"></a>
                        </div>

                        <div class="text-[12px] text-neutral-600 dark:text-fg-dim"
                            x-text="project.environmentCount"></div>
                        <div class="text-[12px] text-neutral-600 dark:text-fg-dim"
                            x-text="project.resourceCount"></div>
                        <p class="project-description truncate text-[12px] text-neutral-500 dark:text-fg-dim"
                            x-text="project.description || '-'"></p>

                        <div class="flex items-center justify-end gap-0.5">
                            <a x-show="project.addResourceHref" :href="project.addResourceHref" {{ wireNavigate() }}
                                class="flex size-7 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                title="Add resource" :aria-label="`Add resource to ${project.name}`">
                                <x-reicon name="plus" class="size-3.5" />
                            </a>
                            <a x-show="project.settingsHref" :href="project.settingsHref" {{ wireNavigate() }}
                                class="flex size-7 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                title="Project settings" :aria-label="`Open settings for ${project.name}`">
                                <x-reicon name="settings" class="size-3.5" />
                            </a>
                        </div>
                    </div>
                </template>

                <footer x-show="totalPages > 1"
                    class="flex min-h-11 items-center justify-between border-t border-neutral-200 px-4 text-[11px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                    <span x-text="`${rangeStart}-${rangeEnd} of ${filteredProjects.length}`"></span>
                    <div class="flex items-center gap-1">
                        <button type="button" x-on:click="previousPage" :disabled="page === 1"
                            class="flex size-7 items-center justify-center rounded-md border border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black disabled:pointer-events-none disabled:opacity-35 dark:border-white/[0.08] dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                            aria-label="Previous page">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none"
                                aria-hidden="true">
                                <path d="m15 5-7 7 7 7" stroke="currentColor" stroke-width="1.7"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>
                        <button type="button" x-on:click="nextPage" :disabled="page >= totalPages"
                            class="flex size-7 items-center justify-center rounded-md border border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black disabled:pointer-events-none disabled:opacity-35 dark:border-white/[0.08] dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                            aria-label="Next page">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none"
                                aria-hidden="true">
                                <path d="m9 5 7 7-7 7" stroke="currentColor" stroke-width="1.7"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>
                    </div>
                </footer>
            </div>

            <div x-show="filteredProjects.length === 0"
                class="flex min-h-52 flex-col items-center justify-center rounded-xl border border-neutral-200 bg-white px-6 text-center dark:border-white/[0.08] dark:bg-white/[0.025]">
                <x-reicon name="search" class="mb-3 size-6 text-neutral-300 dark:text-fg-faint" />
                <p class="text-[13px] font-medium">No matching projects</p>
                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                    Try a different search.
                </p>
            </div>
        @endif
    </div>
</div>

<script>
    function projectsIndex() {
        return {
            search: '',
            sortBy: 'name-asc',
            sortOpen: false,
            viewMode: localStorage.getItem('projects-view') || 'grid',
            page: 1,
            pageSize: 12,
            projects: @js($projectsJs),
            sortOptions: [{
                    value: 'name-asc',
                    label: 'Name A–Z'
                },
                {
                    value: 'name-desc',
                    label: 'Name Z–A'
                },
                {
                    value: 'resources',
                    label: 'Most resources'
                },
                {
                    value: 'environments',
                    label: 'Most environments'
                },
            ],
            get filteredProjects() {
                const query = this.search.trim().toLowerCase();
                const projects = this.projects.filter((project) => {
                    const searchable = [project.name, project.description]
                        .filter(Boolean)
                        .join(' ')
                        .toLowerCase();

                    return !query || searchable.includes(query);
                });

                return projects.sort((first, second) => {
                    if (this.sortBy === 'name-desc') {
                        return second.name.localeCompare(first.name);
                    }
                    if (this.sortBy === 'resources') {
                        return second.resourceCount - first.resourceCount ||
                            first.name.localeCompare(second.name);
                    }
                    if (this.sortBy === 'environments') {
                        return second.environmentCount - first.environmentCount ||
                            first.name.localeCompare(second.name);
                    }

                    return first.name.localeCompare(second.name);
                });
            },
            get totalPages() {
                return Math.max(1, Math.ceil(this.filteredProjects.length / this.pageSize));
            },
            get paginatedProjects() {
                if (this.page > this.totalPages) {
                    this.page = this.totalPages;
                }

                const start = (this.page - 1) * this.pageSize;
                return this.filteredProjects.slice(start, start + this.pageSize);
            },
            get rangeStart() {
                return this.filteredProjects.length === 0 ? 0 : ((this.page - 1) * this.pageSize) + 1;
            },
            get rangeEnd() {
                return Math.min(this.page * this.pageSize, this.filteredProjects.length);
            },
            setViewMode(mode) {
                this.viewMode = mode;
                this.page = 1;
                localStorage.setItem('projects-view', mode);
            },
            previousPage() {
                this.page = Math.max(1, this.page - 1);
            },
            nextPage() {
                this.page = Math.min(this.totalPages, this.page + 1);
            },
        };
    }
</script>
