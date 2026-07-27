<div>
    <x-slot:title>
        {{ data_get_str($environment, 'name')->limit(10) }} > Resources | Coolify
    </x-slot>
    <div x-data="resourceIndex()" class="w-full">
        <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="truncate">{{ $environment->name }}</h1>
                <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                    <span x-text="`${resources.length} ${resources.length === 1 ? 'resource' : 'resources'}`"></span>
                    in {{ $project->name }}
                </p>
            </div>
            @can('createAnyResource')
                <a href="{{ route('project.resource.create', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}"
                    {{ wireNavigate() }} class="button bg-coollabs text-white hover:bg-coollabs-100">
                    <x-reicon name="plus" class="size-3.5" />
                    New resource
                </a>
            @endcan
        </header>

        @if ($environment->isEmpty())
            <div
                class="flex min-h-80 flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-50 px-6 text-center dark:border-white/[0.1] dark:bg-white/[0.02]">
                <div
                    class="mb-4 flex size-11 items-center justify-center rounded-xl border border-neutral-200 bg-white text-neutral-400 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-faint">
                    <x-reicon name="layers" class="size-5" />
                </div>
                <h2 class="text-[15px] font-semibold">No resources yet</h2>
                <p class="mt-1 max-w-sm text-[13px] text-neutral-500 dark:text-fg-dim">
                    Add an application, database, or service to this environment.
                </p>
                @can('createAnyResource')
                    <a href="{{ route('project.resource.create', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}"
                        {{ wireNavigate() }} class="button mt-5">
                        <x-reicon name="plus" class="size-3.5" />
                        Add resource
                    </a>
                @endcan
            </div>
        @else
            <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full sm:max-w-sm">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input x-model.debounce.150ms="search" x-on:input="page = 1" type="search"
                        placeholder="Search resources"
                        class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-neutral-300! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                    <button x-cloak x-show="search" x-on:click="search = ''; page = 1" type="button"
                        class="absolute top-1/2 right-2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
                        aria-label="Clear search">
                        <span class="text-sm leading-none">×</span>
                    </button>
                </div>

                <div class="flex items-center gap-2">
                    <div class="relative" x-on:click.outside="filterOpen = false">
                        <button type="button" class="button" x-on:click="filterOpen = !filterOpen">
                            <svg class="size-3.5 opacity-65" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M4 6h16M7 12h10M10 18h4" stroke="currentColor" stroke-width="1.7"
                                    stroke-linecap="round" />
                            </svg>
                            Filter
                        </button>
                        <div x-cloak x-show="filterOpen" x-transition.origin.top.right
                            class="absolute top-9 right-0 z-50 w-48 rounded-lg border border-neutral-200 bg-white p-1 shadow-modal dark:border-white/[0.1] dark:bg-raised">
                            <template x-for="option in filterOptions" :key="option.value">
                                <button type="button"
                                    class="flex h-8 w-full items-center rounded-md px-2 text-left text-[12px] text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                    x-on:click="typeFilter = option.value; filterOpen = false; page = 1">
                                    <span class="flex-1" x-text="option.label"></span>
                                    <svg x-show="typeFilter === option.value" class="size-3.5 text-warning"
                                        viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                        <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor"
                                            stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div class="relative" x-on:click.outside="sortOpen = false">
                        <button type="button" class="button" x-on:click="sortOpen = !sortOpen">
                            <svg class="size-3.5 opacity-65" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M8 5v14m0 0-3-3m3 3 3-3M16 19V5m0 0-3 3m3-3 3 3"
                                    stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                            Sort
                        </button>
                        <div x-cloak x-show="sortOpen" x-transition.origin.top.right
                            class="absolute top-9 right-0 z-50 w-48 rounded-lg border border-neutral-200 bg-white p-1 shadow-modal dark:border-white/[0.1] dark:bg-raised">
                            <template x-for="option in sortOptions" :key="option.value">
                                <button type="button"
                                    class="flex h-8 w-full items-center rounded-md px-2 text-left text-[12px] text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
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
                        class="flex h-8 items-center rounded-lg border border-neutral-200 bg-white p-0.5 dark:border-white/[0.08] dark:bg-white/[0.035]">
                        <button type="button" x-on:click="setViewMode('table')"
                            class="flex size-6.5 items-center justify-center rounded-md transition-colors"
                            :class="viewMode === 'table'
                                ?
                                'bg-coollabs/10 text-coollabs dark:bg-warning/15 dark:text-warning' :
                                'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'"
                            aria-label="Table view" title="Table view">
                            <x-reicon name="unordered-list" class="size-3.5" />
                        </button>
                        <button type="button" x-on:click="setViewMode('grid')"
                            class="flex size-6.5 items-center justify-center rounded-md transition-colors"
                            :class="viewMode === 'grid'
                                ?
                                'bg-coollabs/10 text-coollabs dark:bg-warning/15 dark:text-warning' :
                                'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'"
                            aria-label="Grid view" title="Grid view">
                            <x-reicon name="grid" class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>

            <div x-show="viewMode === 'table'"
                class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
                <div
                    class="environment-resource-grid border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                    <div>Resource</div>
                    <div>Type</div>
                    <div>Status</div>
                    <div class="resource-domain">Domain</div>
                    <div>Server</div>
                    <div class="resource-tags">Tags</div>
                    <div></div>
                </div>

                <template x-for="item in paginatedResources" :key="item.uuid">
                    <div
                        class="environment-resource-grid group min-h-14 items-center border-b border-neutral-200 px-4 py-2.5 transition-colors last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-dim">
                                <template x-if="item.type === 'application'">
                                    <x-reicon name="browser-code" class="size-4" />
                                </template>
                                <template x-if="item.type === 'database'">
                                    <x-reicon name="database" class="size-4" />
                                </template>
                                <template x-if="item.type === 'service'">
                                    <x-reicon name="layers" class="size-4" />
                                </template>
                            </div>
                            <div class="min-w-0">
                                <a :href="item.hrefLink" {{ wireNavigate() }}
                                    class="block truncate text-[13px] font-semibold text-black hover:underline dark:text-fg"
                                    x-text="item.name"></a>
                                <p class="truncate text-[11px] text-neutral-500 dark:text-fg-faint"
                                    x-text="item.description || item.fqdn || item.uuid"></p>
                            </div>
                        </div>

                        <div class="truncate text-[12px] text-neutral-600 dark:text-fg-dim"
                            x-text="item.typeLabel"></div>

                        <div>
                            <x-status-badge dynamic>
                                <span class="size-1.5 shrink-0 rounded-full"
                                    x-bind:class="statusDotClass(item)"></span>
                                <span class="truncate" x-text="statusLabel(item)"></span>
                            </x-status-badge>
                        </div>

                        <div class="resource-domain min-w-0">
                            <template x-if="item.fqdn">
                                <a :href="firstDomain(item.fqdn)" target="_blank"
                                    class="block truncate text-[12px] text-neutral-600 hover:underline dark:text-fg-dim"
                                    x-text="displayDomain(item.fqdn)"></a>
                            </template>
                            <span x-show="!item.fqdn" class="text-[12px] text-neutral-400 dark:text-fg-faint">—</span>
                        </div>

                        <div class="truncate text-[12px] text-neutral-600 dark:text-fg-dim"
                            x-text="item.destination?.server?.name || 'Unknown'"></div>

                        <div class="resource-tags flex min-w-0 items-center gap-1 overflow-hidden">
                            <template x-for="tag in item.tags.slice(0, 2)" :key="tag.id">
                                <a :href="`/tags/${tag.name}`"
                                    class="max-w-24 truncate rounded-md border border-neutral-200 bg-neutral-50 px-1.5 py-0.5 text-[10px] text-neutral-500 hover:text-black dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-faint dark:hover:text-fg"
                                    x-text="tag.name"></a>
                            </template>
                            <span x-show="item.tags.length > 2"
                                class="text-[10px] text-neutral-400 dark:text-fg-faint"
                                x-text="`+${item.tags.length - 2}`"></span>
                            <span x-show="item.tags.length === 0"
                                class="text-[12px] text-neutral-400 dark:text-fg-faint">—</span>
                        </div>

                        <a :href="item.hrefLink" {{ wireNavigate() }}
                            class="flex size-7 items-center justify-center rounded-md text-neutral-400 opacity-0 transition-all hover:bg-neutral-100 hover:text-black group-hover:opacity-100 focus-visible:opacity-100 dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                            :aria-label="`Open ${item.name}`">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="m9 5 7 7-7 7" stroke="currentColor" stroke-width="1.7"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </div>
                </template>

                <div x-show="filteredResources.length === 0"
                    class="flex min-h-52 flex-col items-center justify-center px-6 text-center">
                    <x-reicon name="search" class="mb-3 size-6 text-neutral-300 dark:text-fg-faint" />
                    <p class="text-[13px] font-medium">No matching resources</p>
                    <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                        Try a different search or filter.
                    </p>
                </div>

                <footer
                    class="flex min-h-11 items-center justify-between border-t border-neutral-200 px-4 text-[11px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                    <span
                        x-text="filteredResources.length === 0 ? '0 resources' : `${rangeStart}-${rangeEnd} of ${filteredResources.length}`"></span>
                    <div class="flex items-center gap-1">
                        <button type="button" x-on:click="previousPage" :disabled="page === 1"
                            class="flex size-7 items-center justify-center rounded-md border border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black disabled:pointer-events-none disabled:opacity-35 dark:border-white/[0.08] dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                            aria-label="Previous page">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="m15 5-7 7 7 7" stroke="currentColor" stroke-width="1.7"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>
                        <button type="button" x-on:click="nextPage" :disabled="page >= totalPages"
                            class="flex size-7 items-center justify-center rounded-md border border-neutral-200 text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black disabled:pointer-events-none disabled:opacity-35 dark:border-white/[0.08] dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                            aria-label="Next page">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="m9 5 7 7-7 7" stroke="currentColor" stroke-width="1.7"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>
                    </div>
                </footer>
            </div>

            <div x-cloak x-show="viewMode === 'grid'">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <template x-for="item in paginatedResources" :key="item.uuid">
                        <article
                            class="group flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                            <div class="flex items-start gap-3">
                                <div
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                    <template x-if="item.type === 'application'">
                                        <x-reicon name="browser-code" class="size-4" />
                                    </template>
                                    <template x-if="item.type === 'database'">
                                        <x-reicon name="database" class="size-4" />
                                    </template>
                                    <template x-if="item.type === 'service'">
                                        <x-reicon name="layers" class="size-4" />
                                    </template>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <a :href="item.hrefLink" {{ wireNavigate() }}
                                        class="block truncate text-[13px] font-semibold text-black hover:underline dark:text-fg"
                                        x-text="item.name"></a>
                                    <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint"
                                        x-text="item.typeLabel"></p>
                                </div>
                                <x-status-badge dynamic>
                                    <span class="size-1.5 shrink-0 rounded-full"
                                        x-bind:class="statusDotClass(item)"></span>
                                    <span class="truncate" x-text="statusLabel(item)"></span>
                                </x-status-badge>
                            </div>

                            <div class="mt-auto flex items-center justify-between gap-3 pt-4">
                                <p class="min-w-0 truncate text-[11px] text-neutral-500 dark:text-fg-dim"
                                    x-text="displayDomain(item.fqdn) || item.description || item.destination?.server?.name || 'No endpoint'">
                                </p>
                                <a :href="item.hrefLink" {{ wireNavigate() }}
                                    class="flex size-7 shrink-0 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                    :aria-label="`Open ${item.name}`">
                                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none"
                                        aria-hidden="true">
                                        <path d="m9 5 7 7-7 7" stroke="currentColor" stroke-width="1.7"
                                            stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </a>
                            </div>
                        </article>
                    </template>
                </div>

                <div x-show="filteredResources.length === 0"
                    class="flex min-h-52 flex-col items-center justify-center rounded-xl border border-neutral-200 bg-white px-6 text-center dark:border-white/[0.08] dark:bg-white/[0.025]">
                    <x-reicon name="search" class="mb-3 size-6 text-neutral-300 dark:text-fg-faint" />
                    <p class="text-[13px] font-medium">No matching resources</p>
                    <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                        Try a different search or filter.
                    </p>
                </div>

                <footer
                    class="mt-3 flex min-h-11 items-center justify-between rounded-xl border border-neutral-200 bg-white px-4 text-[11px] text-neutral-500 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                    <span
                        x-text="filteredResources.length === 0 ? '0 resources' : `${rangeStart}-${rangeEnd} of ${filteredResources.length}`"></span>
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
        @endif
    </div>
</div>

<script>
    function resourceIndex() {
        return {
            search: '',
            typeFilter: 'all',
            sortBy: 'name-asc',
            viewMode: localStorage.getItem('environment-resource-view') || 'table',
            filterOpen: false,
            sortOpen: false,
            page: 1,
            pageSize: 10,
            resources: [
                ...@js($applicationsJs),
                ...@js($postgresqlsJs),
                ...@js($redisJs),
                ...@js($mongodbsJs),
                ...@js($mysqlsJs),
                ...@js($mariadbsJs),
                ...@js($keydbsJs),
                ...@js($dragonfliesJs),
                ...@js($clickhousesJs),
                ...@js($servicesJs),
            ],
            filterOptions: [{
                    value: 'all',
                    label: 'All resources'
                },
                {
                    value: 'application',
                    label: 'Applications'
                },
                {
                    value: 'database',
                    label: 'Databases'
                },
                {
                    value: 'service',
                    label: 'Services'
                },
            ],
            sortOptions: [{
                    value: 'name-asc',
                    label: 'Name A–Z'
                },
                {
                    value: 'name-desc',
                    label: 'Name Z–A'
                },
                {
                    value: 'type',
                    label: 'Resource type'
                },
                {
                    value: 'status',
                    label: 'Status'
                },
            ],
            get filteredResources() {
                const query = this.search.trim().toLowerCase();
                const items = this.resources.filter((item) => {
                    const matchesType = this.typeFilter === 'all' || item.type === this.typeFilter;
                    const searchable = [
                        item.name,
                        item.fqdn,
                        item.description,
                        item.typeLabel,
                        item.status,
                        item.destination?.server?.name,
                        ...(item.tags || []).map((tag) => tag.name),
                    ].filter(Boolean).join(' ').toLowerCase();

                    return matchesType && (!query || searchable.includes(query));
                });

                return items.sort((first, second) => {
                    if (this.sortBy === 'name-desc') {
                        return second.name.localeCompare(first.name);
                    }
                    if (this.sortBy === 'type') {
                        return first.typeLabel.localeCompare(second.typeLabel) ||
                            first.name.localeCompare(second.name);
                    }
                    if (this.sortBy === 'status') {
                        return this.statusLabel(first).localeCompare(this.statusLabel(second)) ||
                            first.name.localeCompare(second.name);
                    }

                    return first.name.localeCompare(second.name);
                });
            },
            get totalPages() {
                return Math.max(1, Math.ceil(this.filteredResources.length / this.pageSize));
            },
            get paginatedResources() {
                if (this.page > this.totalPages) {
                    this.page = this.totalPages;
                }

                const start = (this.page - 1) * this.pageSize;
                return this.filteredResources.slice(start, start + this.pageSize);
            },
            get rangeStart() {
                return this.filteredResources.length === 0 ? 0 : ((this.page - 1) * this.pageSize) + 1;
            },
            get rangeEnd() {
                return Math.min(this.page * this.pageSize, this.filteredResources.length);
            },
            previousPage() {
                this.page = Math.max(1, this.page - 1);
            },
            nextPage() {
                this.page = Math.min(this.totalPages, this.page + 1);
            },
            setViewMode(mode) {
                this.viewMode = mode;
                this.page = 1;
                localStorage.setItem('environment-resource-view', mode);
            },
            statusState(item) {
                return String(item.status || 'unknown').split(':')[0].toLowerCase();
            },
            statusLabel(item) {
                const state = this.statusState(item);
                return state.charAt(0).toUpperCase() + state.slice(1);
            },
            statusTone(item) {
                const state = this.statusState(item);
                if (state === 'running') {
                    return 'success';
                }
                if (['starting', 'restarting', 'degraded'].includes(state)) {
                    return 'warning';
                }
                if (['exited', 'stopped', 'failed'].includes(state)) {
                    return 'error';
                }

                return 'neutral';
            },
            statusDotClass(item) {
                return {
                    success: 'bg-emerald-500',
                    warning: 'bg-warning',
                    error: 'bg-red-500',
                    neutral: 'bg-neutral-400 dark:bg-neutral-500',
                } [this.statusTone(item)];
            },
            firstDomain(fqdn) {
                return String(fqdn).split(',')[0].trim();
            },
            displayDomain(fqdn) {
                if (!fqdn) {
                    return '';
                }

                return this.firstDomain(fqdn).replace(/^https?:\/\//, '');
            },
        };
    }
</script>
