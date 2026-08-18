<div class="application-settings-form w-full">
    <x-slot:title>
        Tags | Coolify
    </x-slot>

    <header @class([
        'mb-5 flex items-start justify-between gap-4',
        'lg:hidden' => isset($tag),
    ])>
        <div class="min-w-0">
            <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Tags</h1>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                @if ($tags->isEmpty())
                    Group applications and services for bulk deploys
                @elseif (isset($tag))
                    Manage webhook deploys and resources for this tag
                @else
                    {{ $tags->count() }} {{ Str::plural('tag', $tags->count()) }} for bulk deploys and grouping
                @endif
            </p>
        </div>
    </header>

    @if ($tags->isEmpty())
        <x-empty title="No tags yet"
            description="Open a resource and add a tag to start grouping related deployments."
            icon-name="tags" />
    @elseif (! isset($tag))
        <div x-data="tagsIndex()" class="w-full">
            <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full sm:max-w-sm">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input x-model.debounce.150ms="search" x-on:input="page = 1" type="search"
                        placeholder="Search tags"
                        class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                    <button x-cloak x-show="search" x-on:click="search = ''; page = 1" type="button"
                        class="absolute top-1/2 right-2 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
                        aria-label="Clear search">
                        <span class="text-sm leading-none">×</span>
                    </button>
                </div>

                <div class="flex items-center gap-2">
                    <x-table.dropdown panel-class="w-52!">
                        <x-slot:trigger>
                            <button type="button" class="button" aria-haspopup="listbox" :aria-expanded="open">
                            <svg class="size-3.5 opacity-65" viewBox="0 0 24 24" fill="none"
                                aria-hidden="true">
                                <path d="M8 5v14m0 0-3-3m3 3 3-3M16 19V5m0 0-3 3m3-3 3 3"
                                    stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                            Sort
                            </button>
                        </x-slot:trigger>
                            <template x-for="option in sortOptions" :key="option.value">
                                <button type="button"
                                    class="flex h-9 w-full items-center rounded-md px-2 text-left text-[12px] text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                    x-on:click="sortBy = option.value; close(); page = 1">
                                    <span class="flex-1" x-text="option.label"></span>
                                    <svg x-show="sortBy === option.value" class="size-3.5 text-warning"
                                        viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                        <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor"
                                            stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                            </template>
                    </x-table.dropdown>

                    <div
                        class="flex h-9 items-center rounded-lg border border-neutral-200 bg-white p-0.5 dark:border-white/[0.08] dark:bg-white/[0.035]">
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
                    <template x-for="tag in paginatedTags" :key="tag.id">
                        <article
                            class="group relative flex min-h-28 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                            <a :href="tag.href" {{ wireNavigate() }} class="absolute inset-0 rounded-xl"
                                :aria-label="`Open ${tag.name}`"></a>

                            <div class="flex items-start gap-3">
                                <div
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                    <x-reicon name="tags" class="size-4" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h2 class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg"
                                        x-text="tag.name"></h2>
                                    <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                        Team tag
                                    </p>
                                </div>
                            </div>

                            <div class="mt-auto flex items-center pt-4">
                                <span class="text-[11px] text-neutral-500 dark:text-fg-dim"
                                    x-text="`${tag.resourceCount} ${tag.resourceCount === 1 ? 'resource' : 'resources'}`"></span>
                            </div>
                        </article>
                    </template>
                </div>
                <x-client-pagination x-show="filteredTags.length > 0" class="mt-3 rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]"
                    summary="`${rangeStart}-${rangeEnd} of ${filteredTags.length}`" page-size-model="pageSize"
                    storage-key="coolify.page-size.tags" :options="[12, 24, 48, 96]" />
            </div>

            <div x-show="viewMode === 'table'"
                class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
                <div
                    class="tags-table-grid border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                    <div>Tag</div>
                    <div>Resources</div>
                    <div>Applications</div>
                    <div class="tag-services">Services</div>
                </div>

                <template x-for="tag in paginatedTags" :key="tag.id">
                    <a :href="tag.href" {{ wireNavigate() }}
                        class="tags-table-grid group min-h-14 items-center border-b border-neutral-200 px-4 py-2.5 transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]"
                        :aria-label="`Open ${tag.name}`">
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-dim">
                                <x-reicon name="tags" class="size-4" />
                            </div>
                            <span class="truncate text-[13px] font-semibold text-black dark:text-fg"
                                x-text="tag.name"></span>
                        </div>

                        <div class="text-[12px] text-neutral-600 dark:text-fg-dim" x-text="tag.resourceCount"></div>
                        <div class="text-[12px] text-neutral-600 dark:text-fg-dim" x-text="tag.applicationsCount"></div>
                        <div class="tag-services text-[12px] text-neutral-600 dark:text-fg-dim"
                            x-text="tag.servicesCount"></div>
                    </a>
                </template>
                <x-client-pagination x-show="filteredTags.length > 0"
                    summary="`${rangeStart}-${rangeEnd} of ${filteredTags.length}`" page-size-model="pageSize"
                    storage-key="coolify.page-size.tags" :options="[12, 24, 48, 96]" />
            </div>

            <div x-show="filteredTags.length === 0"
                class="flex min-h-52 flex-col items-center justify-center rounded-xl border border-neutral-200 bg-white px-6 text-center dark:border-white/[0.08] dark:bg-white/[0.025]">
                <x-reicon name="search" class="mb-3 size-6 text-neutral-300 dark:text-fg-faint" />
                <p class="text-[13px] font-medium">No matching tags</p>
                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                    Try a different search.
                </p>
            </div>
        </div>

        <script>
            function tagsIndex() {
                return {
                    search: '',
                    sortBy: 'name-asc',
                    sortOpen: false,
                    viewMode: localStorage.getItem('tags-view') || 'grid',
                    page: 1,
                    pageSize: 12,
                    tags: @js($tagsJs),
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
                    ],
                    get filteredTags() {
                        const query = this.search.trim().toLowerCase();
                        const tags = this.tags.filter((tag) => {
                            return !query || tag.name.toLowerCase().includes(query);
                        });

                        return tags.sort((first, second) => {
                            if (this.sortBy === 'name-desc') {
                                return second.name.localeCompare(first.name);
                            }
                            if (this.sortBy === 'resources') {
                                return second.resourceCount - first.resourceCount ||
                                    first.name.localeCompare(second.name);
                            }

                            return first.name.localeCompare(second.name);
                        });
                    },
                    get totalPages() {
                        return Math.max(1, Math.ceil(this.filteredTags.length / this.pageSize));
                    },
                    get paginatedTags() {
                        if (this.page > this.totalPages) {
                            this.page = this.totalPages;
                        }

                        const start = (this.page - 1) * this.pageSize;
                        return this.filteredTags.slice(start, start + this.pageSize);
                    },
                    get rangeStart() {
                        return this.filteredTags.length === 0 ? 0 : ((this.page - 1) * this.pageSize) + 1;
                    },
                    get rangeEnd() {
                        return Math.min(this.page * this.pageSize, this.filteredTags.length);
                    },
                    setViewMode(mode) {
                        this.viewMode = mode;
                        this.page = 1;
                        localStorage.setItem('tags-view', mode);
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
    @else
        @php
            $resourceCount = ($applications?->count() ?? 0) + ($services?->count() ?? 0);
        @endphp

        <div class="flex flex-col gap-6">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div
                    class="rounded-xl border border-neutral-200 bg-white p-3 dark:border-white/[0.08] dark:bg-white/[0.025]">
                    <p class="text-[11px] font-medium text-neutral-500 dark:text-fg-faint">Resources</p>
                    <p class="mt-1 text-[20px] font-semibold tracking-tight text-black dark:text-fg">
                        {{ $resourceCount }}
                    </p>
                    <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-dim">
                        Applications & services
                    </p>
                </div>
                <div
                    class="rounded-xl border border-neutral-200 bg-white p-3 dark:border-white/[0.08] dark:bg-white/[0.025]">
                    <p class="text-[11px] font-medium text-neutral-500 dark:text-fg-faint">Applications</p>
                    <p class="mt-1 text-[20px] font-semibold tracking-tight text-black dark:text-fg">
                        {{ $applications?->count() ?? 0 }}
                    </p>
                    <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-dim">
                        Using this tag
                    </p>
                </div>
                <div
                    class="rounded-xl border border-neutral-200 bg-white p-3 dark:border-white/[0.08] dark:bg-white/[0.025]">
                    <p class="text-[11px] font-medium text-neutral-500 dark:text-fg-faint">Active deployments</p>
                    <p class="mt-1 text-[20px] font-semibold tracking-tight text-black dark:text-fg">
                        {{ collect($deploymentsPerTagPerServer ?? [])->flatten(1)->count() }}
                    </p>
                    <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-dim">
                        Queued or running
                    </p>
                </div>
            </div>

            <x-application.settings-section :title="$tag->name"
                description="Use this webhook to deploy every resource with this tag.">
                <x-slot:actions>
                    <x-modal-confirmation title="Redeploy all resources with this tag?"
                        buttonTitle="Redeploy all" submitAction="redeployAll" :actions="[
                            'All resources with this tag will be redeployed.',
                            'During redeploy resources will be temporarily unavailable.',
                        ]"
                        confirmationText="{{ $tag->name }}"
                        confirmationLabel="Please confirm the execution of the actions by entering the Tag Name below"
                        shortConfirmationLabel="Tag Name" :confirmWithPassword="false"
                        step2ButtonText="Redeploy All" />
                </x-slot:actions>

                <x-forms.input readonly label="Deploy webhook URL" id="webhook" />
            </x-application.settings-section>

            <x-application.settings-section title="Resources"
                description="{{ $resourceCount }} {{ Str::plural('resource', $resourceCount) }} use this tag.">
                @if ($resourceCount === 0)
                    <x-empty title="No resources use this tag"
                        description="Add this tag to an application or service to see it here."
                        icon-name="tags" size="sm" />
                @else
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($applications ?? [] as $application)
                            <a {{ wireNavigate() }} href="{{ $application->link() }}"
                                class="group flex min-h-24 flex-col rounded-lg border border-neutral-200 bg-neutral-50/70 p-3 transition-colors hover:border-neutral-300 hover:no-underline dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                                <div class="flex items-start gap-2.5">
                                    <div
                                        class="flex size-7 shrink-0 items-center justify-center rounded-md border border-neutral-200 bg-white text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-dim">
                                        <x-reicon name="browser-code" class="size-3.5" />
                                    </div>
                                    <div class="min-w-0">
                                        <h3
                                            class="truncate text-[12px]! leading-4! font-semibold! text-black dark:text-fg">
                                            {{ $application->name }}
                                        </h3>
                                        <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                            {{ $application->project()->name }}/{{ $application->environment->name }}
                                        </p>
                                    </div>
                                </div>
                                <p class="mt-auto truncate pt-3 text-[11px] text-neutral-500 dark:text-fg-dim">
                                    {{ $application->description ?: 'Application' }}
                                </p>
                            </a>
                        @endforeach

                        @foreach ($services ?? [] as $service)
                            <a {{ wireNavigate() }} href="{{ $service->link() }}"
                                class="group flex min-h-24 flex-col rounded-lg border border-neutral-200 bg-neutral-50/70 p-3 transition-colors hover:border-neutral-300 hover:no-underline dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                                <div class="flex items-start gap-2.5">
                                    <div
                                        class="flex size-7 shrink-0 items-center justify-center rounded-md border border-neutral-200 bg-white text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-dim">
                                        <x-reicon name="layers" class="size-3.5" />
                                    </div>
                                    <div class="min-w-0">
                                        <h3
                                            class="truncate text-[12px]! leading-4! font-semibold! text-black dark:text-fg">
                                            {{ $service->name }}
                                        </h3>
                                        <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                            {{ $service->project()->name }}/{{ $service->environment->name }}
                                        </p>
                                    </div>
                                </div>
                                <p class="mt-auto truncate pt-3 text-[11px] text-neutral-500 dark:text-fg-dim">
                                    {{ $service->description ?: 'Service' }}
                                </p>
                            </a>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>

            <x-application.settings-section title="Active deployments"
                description="Queued and running deployments for applications using this tag." flush>
                <x-slot:actions>
                    @if (count($deploymentsPerTagPerServer ?? []) > 0)
                        <x-loading />
                    @endif
                </x-slot:actions>

                <div wire:poll="getDeployments" class="overflow-x-auto">
                    @if (count($deploymentsPerTagPerServer ?? []) === 0)
                        <div class="p-3">
                            <x-empty title="No active deployments"
                                description="Deployments will appear here while they are queued or running."
                                icon-name="play-circle" size="sm" />
                        </div>
                    @else
                        <div
                            class="grid min-w-[620px] grid-cols-[minmax(0,1fr)_minmax(10rem,.55fr)_7rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                            <div>Resource</div>
                            <div>Server</div>
                            <div>Status</div>
                        </div>

                        @foreach ($deploymentsPerTagPerServer as $serverName => $deployments)
                            @foreach ($deployments as $deployment)
                                <a {{ wireNavigate() }} href="{{ data_get($deployment, 'deployment_url') }}"
                                    class="grid min-h-13 min-w-[620px] grid-cols-[minmax(0,1fr)_minmax(10rem,.55fr)_7rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                                    <div class="truncate font-medium text-black dark:text-fg">
                                        {{ data_get($deployment, 'application_name') }}
                                    </div>
                                    <div class="truncate text-neutral-500 dark:text-fg-dim">{{ $serverName }}</div>
                                    <div>
                                        <x-status-badge :status="str(data_get($deployment, 'status'))->headline()"
                                            :type="data_get($deployment, 'status') === 'in_progress' ? 'warning' : 'neutral'" />
                                    </div>
                                </a>
                            @endforeach
                        @endforeach
                    @endif
                </div>
            </x-application.settings-section>
        </div>
    @endif
</div>
