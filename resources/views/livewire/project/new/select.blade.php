<div class="application-settings-form" x-data x-init="$wire.loadServers">
    <div x-data="searchResources()">
        @if ($current_step === 'type')
            <x-application.settings-section title="Choose a resource" flush>
                <x-slot:actions>
                    <button type="button" class="button" :disabled="loading" @click="loadResources">
                        <x-reicon name="refresh" class="size-3.5" />
                        Reload
                    </button>
                </x-slot:actions>
                <div
                    class="flex flex-col gap-3 border-b border-neutral-200 p-4 dark:border-white/[0.08] sm:flex-row sm:items-center sm:justify-between">
                    <div class="relative min-w-0 flex-1">
                        <x-reicon name="search"
                            class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                        <input autocomplete="off" x-ref="searchInput" x-model="search" type="search"
                            placeholder="Search resources"
                            class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-accent! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint"
                            @keydown.window.slash.prevent="$refs.searchInput.focus()">
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <x-table.dropdown panel-class="w-48!">
                            <x-slot:trigger><button type="button" class="button"
                                aria-haspopup="listbox" :aria-expanded="open">
                                <x-reicon name="filter" class="size-3.5" />
                                Filter
                            </button></x-slot:trigger>
                                <div
                                    class="px-2 py-1 text-[10px] font-semibold tracking-wide text-neutral-400 uppercase dark:text-fg-faint">
                                    Resource type
                                </div>
                                <template x-for="option in resourceTypeOptions" :key="option.value">
                                    <button type="button" class="listbox-option" role="option"
                                        :aria-selected="resourceType === option.value"
                                        @click="resourceType = option.value; close()">
                                        <span x-text="option.label"></span>
                                        <x-reicon name="check-circle" class="size-3.5 text-accent"
                                            x-show="resourceType === option.value" />
                                    </button>
                                </template>
                        </x-table.dropdown>

                        <div class="relative w-48" @click.outside="closeCategoryFilter()">
                            <button type="button" class="listbox-trigger"
                                :disabled="loading || categories.length === 0"
                                @click="categoryOpen = !categoryOpen; $nextTick(() => categoryOpen && $refs.categorySearchInput.focus())"
                                aria-haspopup="listbox" aria-controls="resource-category-options"
                                :aria-expanded="categoryOpen"
                                :title="selectedCategory === '' ? 'All categories' : selectedCategory">
                                <span class="listbox-trigger-label capitalize"
                                    x-text="selectedCategory === '' ? 'All categories' : selectedCategory"></span>
                                <svg class="size-3.5 shrink-0 opacity-60" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="m8 9 4-4 4 4m0 6-4 4-4-4" />
                                </svg>
                            </button>
                            <div id="resource-category-options" x-show="categoryOpen" x-cloak
                                x-transition.opacity.duration.120ms role="listbox" aria-label="Service category"
                                @keydown.escape.stop="closeCategoryFilter(true)"
                                class="listbox-panel left-auto! right-0! z-[90]! min-w-56!">
                                <div class="border-b border-neutral-200 p-2 dark:border-white/[0.08]">
                                    <input type="search" x-ref="categorySearchInput" x-model="categorySearch"
                                        placeholder="Search categories"
                                        class="h-8! w-full rounded-md! border-neutral-200! bg-neutral-50! px-2.5! py-0! text-[12px]! shadow-none! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.04]! dark:text-fg!"
                                        @click.stop>
                                </div>
                                <div class="max-h-60 overflow-auto p-1">
                                    <button type="button" class="listbox-option" role="option"
                                        :aria-selected="selectedCategory === ''"
                                        @click="selectedCategory = ''; categorySearch = ''; categoryOpen = false">
                                        <span>All categories</span>
                                        <x-reicon name="check-circle" class="size-3.5 text-accent"
                                            x-show="selectedCategory === ''" />
                                    </button>
                                    <template
                                        x-for="category in categories.filter(category => categorySearch === '' || category.toLowerCase().includes(categorySearch.toLowerCase()))"
                                        :key="category">
                                        <button type="button" class="listbox-option capitalize" role="option"
                                            :aria-selected="selectedCategory === category"
                                            @click="selectedCategory = category; categorySearch = ''; categoryOpen = false">
                                            <span class="truncate" x-text="category"></span>
                                            <x-reicon name="check-circle" class="size-3.5 text-accent"
                                                x-show="selectedCategory === category" />
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </x-application.settings-section>

            <div x-show="loading" class="flex items-center justify-center py-8">
                <x-loading text="Loading resources..." />
            </div>
            <div x-show="!loading" class="mt-6 flex flex-col gap-6">
                <section
                    x-show="(resourceType === 'all' || resourceType === 'applications') && (filteredGitBasedApplications.length > 0 || filteredDockerBasedApplications.length > 0)"
                    class="application-settings-section">
                    <div class="application-settings-section-header">
                        <div class="flex items-center gap-2">
                            <x-reicon name="globe" class="size-4 text-neutral-400 dark:text-fg-faint" />
                            <h2>Applications</h2>
                        </div>
                    </div>
                    <div
                        class="application-settings-section-body grid grid-cols-1 justify-start gap-3 text-left md:grid-cols-2 xl:grid-cols-3">
                        <template x-for="application in filteredGitBasedApplications" :key="application.name">
                            <article role="button" tabindex="0" :aria-label="'Deploy ' + application.name"
                                @click="setType(application.id)" @keydown.enter.self.prevent="setType(application.id)"
                                @keydown.space.self.prevent="setType(application.id)"
                                class="group flex min-h-48 cursor-pointer flex-col rounded-xl border border-neutral-200 bg-white p-4 transition-colors hover:border-neutral-300 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                                <div class="flex min-w-0 items-start gap-3">
                                    <div
                                        class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-neutral-200 bg-neutral-50 dark:border-white/[0.08] dark:bg-white/[0.04]">
                                        <template x-if="!application.logoDark">
                                            <img class="size-full object-contain" :src="application.logo" alt="" />
                                        </template>
                                        <template x-if="application.logoDark">
                                            <span class="size-full">
                                                <img class="size-full object-contain block dark:hidden"
                                                    :src="application.logo" alt="" />
                                                <img class="size-full object-contain hidden dark:block"
                                                    :src="application.logoDark" alt="" />
                                            </span>
                                        </template>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="truncate text-[13px] font-semibold text-black dark:text-fg"
                                            x-text="application.name"></h3>
                                        <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                                            Git source
                                        </p>
                                    </div>
                                </div>

                                <p class="mt-3 line-clamp-2 text-[12px] leading-5 text-neutral-600 dark:text-fg-dim"
                                    x-html="window.sanitizeHTML(application.description)"></p>

                                <div
                                    class="mt-auto flex items-center gap-1.5 border-t border-neutral-200 pt-3 dark:border-white/[0.07]">
                                    <a class="button" :href="application.documentation" target="_blank"
                                        rel="noopener noreferrer" @click.stop>
                                        Docs
                                    </a>
                                    <span class="button button-highlighted ml-auto">
                                        Deploy
                                        <x-reicon name="arrow-right" class="size-3.5" />
                                    </span>
                                </div>
                            </article>
                        </template>

                        <template x-for="application in filteredDockerBasedApplications" :key="application.name">
                            <article role="button" tabindex="0" :aria-label="'Deploy ' + application.name"
                                @click="setType(application.id)" @keydown.enter.self.prevent="setType(application.id)"
                                @keydown.space.self.prevent="setType(application.id)"
                                class="group flex min-h-48 cursor-pointer flex-col rounded-xl border border-neutral-200 bg-white p-4 transition-colors hover:border-neutral-300 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                                <div class="flex min-w-0 items-start gap-3">
                                    <div
                                        class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-neutral-200 bg-neutral-50 dark:border-white/[0.08] dark:bg-white/[0.04]">
                                        <img class="size-full object-contain" :src="application.logo" alt="" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="truncate text-[13px] font-semibold text-black dark:text-fg"
                                            x-text="application.name"></h3>
                                        <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                                            Docker source
                                        </p>
                                    </div>
                                </div>

                                <p class="mt-3 line-clamp-2 text-[12px] leading-5 text-neutral-600 dark:text-fg-dim"
                                    x-text="application.description"></p>

                                <div
                                    class="mt-auto flex items-center gap-1.5 border-t border-neutral-200 pt-3 dark:border-white/[0.07]">
                                    <a class="button" :href="application.documentation" target="_blank"
                                        rel="noopener noreferrer" @click.stop>
                                        Docs
                                    </a>
                                    <span class="button button-highlighted ml-auto">
                                        Deploy
                                        <x-reicon name="arrow-right" class="size-3.5" />
                                    </span>
                                </div>
                            </article>
                        </template>
                    </div>
                </section>

                <section
                    x-show="(resourceType === 'all' || resourceType === 'databases') && filteredDatabases.length > 0"
                    class="application-settings-section">
                    <div class="application-settings-section-header">
                        <div class="flex items-center gap-2">
                            <x-reicon name="database" class="size-4 text-neutral-400 dark:text-fg-faint" />
                            <h2>Databases</h2>
                        </div>
                    </div>
                    <div
                        class="application-settings-section-body grid grid-cols-1 justify-start gap-3 text-left md:grid-cols-2 xl:grid-cols-3">
                        <template x-for="database in filteredDatabases" :key="database.id">
                            <article role="button" tabindex="0" :aria-label="'Deploy ' + database.name"
                                @click="setType(database.id)" @keydown.enter.self.prevent="setType(database.id)"
                                @keydown.space.self.prevent="setType(database.id)"
                                class="group flex min-h-48 cursor-pointer flex-col rounded-xl border border-neutral-200 bg-white p-4 transition-colors hover:border-neutral-300 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                                <div class="flex min-w-0 items-center gap-3">
                                    <div
                                        class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-neutral-200 bg-neutral-50 dark:border-white/[0.08] dark:bg-white/[0.04]">
                                        <template x-if="database.logo && database.logo.trim().startsWith('<')">
                                            <span
                                                class="flex size-full items-center justify-center [&>div]:h-full! [&>div]:w-full! [&>div]:bg-transparent! [&>div]:p-0! [&>div>svg]:h-full! [&>div>svg]:w-full! [&>svg]:h-full! [&>svg]:w-full! [&>svg]:bg-transparent! [&>svg]:p-0!"
                                                x-html="database.logo"></span>
                                        </template>
                                        <template x-if="database.logo && !database.logo.trim().startsWith('<') && !database.logoDark">
                                            <img class="size-full object-contain" :src="database.logo" alt="" />
                                        </template>
                                        <template x-if="database.logo && !database.logo.trim().startsWith('<') && database.logoDark">
                                            <span class="size-full">
                                                <img class="size-full object-contain block dark:hidden"
                                                    :src="database.logo" alt="" />
                                                <img class="size-full object-contain hidden dark:block"
                                                    :src="database.logoDark" alt="" />
                                            </span>
                                        </template>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="truncate text-[13px] font-semibold text-black dark:text-fg"
                                            x-text="database.name"></h3>
                                    </div>
                                </div>

                                <p class="mt-3 line-clamp-2 text-[12px] leading-5 text-neutral-600 dark:text-fg-dim"
                                    x-text="database.description"></p>

                                <div
                                    class="mt-auto flex items-center gap-1.5 border-t border-neutral-200 pt-3 dark:border-white/[0.07]">
                                    <a :href="databaseDocsUrl(database)" target="_blank" rel="noopener noreferrer"
                                        class="button" @click.stop>
                                        Docs
                                    </a>
                                    <a :href="databaseWebsiteUrl(database)" target="_blank" rel="noopener noreferrer"
                                        class="button" @click.stop>
                                        Website
                                    </a>
                                    <span class="button button-highlighted ml-auto">
                                        Deploy
                                        <x-reicon name="arrow-right" class="size-3.5" />
                                    </span>
                                </div>
                            </article>
                        </template>
                    </div>
                </section>

                <section
                    x-show="(resourceType === 'all' || resourceType === 'services') && filteredServices.length > 0"
                    class="application-settings-section">
                    <div class="application-settings-section-header" x-init="loadResources">
                        <div class="flex items-center gap-2">
                            <x-reicon name="layers" class="size-4 text-neutral-400 dark:text-fg-faint" />
                            <h2>Services</h2>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <div x-show="serviceTemplatesLastUpdated"
                                class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                Updated <span x-text="serviceTemplatesLastUpdated"></span>
                            </div>
                        </div>
                    </div>
                    <div class="application-settings-section-body">
                        <x-callout type="info" title="Trademarks policy" class="mb-4">
                            The respective trademarks mentioned here are owned by the respective companies, and use of them
                            does not imply any affiliation or endorsement.
                        </x-callout>

                        <div class="grid grid-cols-1 justify-start gap-3 text-left md:grid-cols-2 xl:grid-cols-3">
                            <template x-for="service in filteredServices" :key="service.name">
                                <article role="button" tabindex="0" :aria-label="'Deploy ' + service.name"
                                    @click="setType('one-click-service-' + service.id)"
                                    @keydown.enter.self.prevent="setType('one-click-service-' + service.id)"
                                    @keydown.space.self.prevent="setType('one-click-service-' + service.id)"
                                    class="group flex min-h-48 cursor-pointer flex-col rounded-xl border border-neutral-200 bg-white p-4 transition-colors hover:border-neutral-300 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                                    <div class="flex min-w-0 items-start gap-3">
                                        <div
                                            class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-neutral-200 bg-neutral-50 dark:border-white/[0.08] dark:bg-white/[0.04]">
                                            <img class="h-full w-full object-contain p-2" :src="service.logo"
                                                x-on:error="if (!$el.dataset.cdnTried) { $el.dataset.cdnTried = 'true'; $el.src = service.logo_cdn_url; } else if (!$el.dataset.defaultTried) { $el.dataset.defaultTried = 'true'; $el.src = service.logo_default_url; }" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <h3 class="truncate text-[13px] font-semibold text-black dark:text-fg"
                                                x-text="service.name"></h3>
                                            <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                                <span x-show="service.templateLastUpdated">Updated </span>
                                                <span x-text="service.templateLastUpdated || 'Template ready'"></span>
                                            </p>
                                        </div>
                                        <span x-show="service.amd_only || service.arm_only"
                                            class="shrink-0 rounded-md border border-amber-300/50 bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:border-warning/20 dark:bg-warning/10 dark:text-warning"
                                            x-text="service.arm_only ? 'ARM only' : 'AMD only'"></span>
                                    </div>

                                    <p class="mt-3 line-clamp-2 text-[12px] leading-5 text-neutral-600 dark:text-fg-dim"
                                        x-text="service.slogan || service.description || 'Deploy this service with a ready-to-use Coolify template.'">
                                    </p>

                                    <div
                                        class="mt-auto flex items-center gap-1.5 border-t border-neutral-200 pt-3 dark:border-white/[0.07]">
                                        <a :href="getDocLink(service) || coolifyDocsUrl(service)" target="_blank"
                                            rel="noopener noreferrer" @mouseenter="resolveDocLink(service)" @click.stop
                                            class="button" :class="{ 'opacity-60': docCheckInProgress[service.name] }">
                                            Docs
                                        </a>
                                        <a x-show="serviceWebsiteUrl(service)" :href="serviceWebsiteUrl(service)"
                                            target="_blank" rel="noopener noreferrer" class="button" @click.stop>
                                            Website
                                        </a>
                                        <span class="button button-highlighted ml-auto">
                                            Deploy
                                            <x-reicon name="arrow-right" class="size-3.5" />
                                        </span>
                                    </div>
                                </article>
                            </template>
                        </div>
                    </div>
                </section>
                <div x-show="visibleResourceCount === 0 && loading === false">
                    <x-empty title="No resources found" description="Try a different search or resource type."
                        icon-name="layers" size="sm" />
                </div>
            </div>
            <script>
                function sortFn(a, b) {
                    return a.name.localeCompare(b.name)
                }

                function searchResources() {
                    return {
                        search: '',
                        resourceType: 'all',
                        resourceTypeOptions: [{
                                value: 'all',
                                label: 'All resources'
                            },
                            {
                                value: 'applications',
                                label: 'Applications'
                            },
                            {
                                value: 'databases',
                                label: 'Databases'
                            },
                            {
                                value: 'services',
                                label: 'Services'
                            }
                        ],
                        filterOpen: false,
                        categoryOpen: false,
                        categorySearch: '',
                        selectedCategory: '',
                        categories: [],
                        loading: false,
                        isSticky: false,
                        selecting: false,
                        services: [],
                        serviceTemplatesLastUpdated: null,
                        gitBasedApplications: [],
                        dockerBasedApplications: [],
                        databases: [],
                        closeCategoryFilter(restoreFocus = false) {
                            if (!this.categoryOpen) return;

                            this.categoryOpen = false;
                            this.categorySearch = '';
                            this.$refs.categorySearchInput?.blur();
                            if (restoreFocus) {
                                this.$nextTick(() => document.getElementById('resource-category-options')
                                    ?.previousElementSibling?.focus());
                            }
                        },
                        databaseLinks: {
                            postgresql: {
                                docs: 'https://www.postgresql.org/docs/',
                                website: 'https://www.postgresql.org/'
                            },
                            mysql: {
                                docs: 'https://dev.mysql.com/doc/',
                                website: 'https://www.mysql.com/'
                            },
                            mariadb: {
                                docs: 'https://mariadb.com/kb/en/documentation/',
                                website: 'https://mariadb.org/'
                            },
                            redis: {
                                docs: 'https://redis.io/docs/latest/',
                                website: 'https://redis.io/'
                            },
                            keydb: {
                                docs: 'https://docs.keydb.dev/',
                                website: 'https://keydb.dev/'
                            },
                            dragonfly: {
                                docs: 'https://www.dragonflydb.io/docs',
                                website: 'https://www.dragonflydb.io/'
                            },
                            mongodb: {
                                docs: 'https://www.mongodb.com/docs/',
                                website: 'https://www.mongodb.com/'
                            },
                            clickhouse: {
                                docs: 'https://clickhouse.com/docs',
                                website: 'https://clickhouse.com/'
                            }
                        },
                        docLinkCache: {}, // Cache resolved doc URLs: { serviceName: url | null }
                        docCheckInProgress: {}, // Track ongoing checks: { serviceName: boolean }
                        setType(type) {
                            if (this.selecting) return;
                            this.selecting = true;
                            this.$wire.setType(type);
                        },
                        async loadResources() {
                            this.loading = true;
                            const {
                                services,
                                serviceTemplatesLastUpdated,
                                categories,
                                gitBasedApplications,
                                dockerBasedApplications,
                                databases
                            } = await this.$wire.loadServices();
                            this.services = services;
                            this.serviceTemplatesLastUpdated = serviceTemplatesLastUpdated;
                            this.categories = categories;
                            this.gitBasedApplications = gitBasedApplications;
                            this.dockerBasedApplications = dockerBasedApplications;
                            this.databases = databases;
                            this.loading = false;
                            this.$nextTick(() => {
                                this.$refs.searchInput.focus();
                            });
                        },
                        extractBaseServiceName(serviceName) {
                            // Convert to lowercase and replace spaces with dashes to match original format
                            const normalized = serviceName.toLowerCase().replace(/\s+/g, '-');
                            // Remove flavor suffixes: -with-*, -without-*
                            return normalized.replace(/-(with|without)-.+$/, '');
                        },
                        coolifyDocsUrl(service) {
                            const baseName = service.docsSlug || this.extractBaseServiceName(service.name);
                            return 'https://coolify.io/docs/services/' + baseName;
                        },
                        officialDocsUrl(service) {
                            return service.documentation || null;
                        },
                        databaseDocsUrl(database) {
                            return this.databaseLinks[database.id]?.docs;
                        },
                        databaseWebsiteUrl(database) {
                            return this.databaseLinks[database.id]?.website;
                        },
                        serviceWebsiteUrl(service) {
                            const source = service.website || service.homepage || service.documentation;
                            if (!source) return null;

                            try {
                                return new URL(source).origin;
                            } catch (error) {
                                return null;
                            }
                        },
                        async checkUrlExists(url) {
                            if (!url) return false;
                            try {
                                const response = await fetch(url, {
                                    method: 'HEAD'
                                });
                                return response.ok;
                            } catch (e) {
                                // CORS error or network error - assume URL exists
                                return true;
                            }
                        },
                        async resolveDocLink(service) {
                            const serviceName = service.name;

                            // Already cached?
                            if (this.docLinkCache.hasOwnProperty(serviceName)) {
                                return this.docLinkCache[serviceName];
                            }

                            // Already checking?
                            if (this.docCheckInProgress[serviceName]) {
                                return null;
                            }

                            this.docCheckInProgress[serviceName] = true;

                            // 1. Try Coolify docs first
                            const coolifyUrl = this.coolifyDocsUrl(service);
                            const coolifyExists = await this.checkUrlExists(coolifyUrl);

                            if (coolifyExists) {
                                this.docLinkCache[serviceName] = coolifyUrl;
                                this.docCheckInProgress[serviceName] = false;
                                return coolifyUrl;
                            }

                            // 2. Fall back to official docs
                            const officialUrl = this.officialDocsUrl(service);
                            if (officialUrl) {
                                const officialExists = await this.checkUrlExists(officialUrl);

                                if (officialExists) {
                                    this.docLinkCache[serviceName] = officialUrl;
                                    this.docCheckInProgress[serviceName] = false;
                                    return officialUrl;
                                }
                            }

                            // 3. Both failed - cache null to hide icon
                            this.docLinkCache[serviceName] = null;
                            this.docCheckInProgress[serviceName] = false;
                            return null;
                        },
                        getDocLink(service) {
                            return this.docLinkCache[service.name];
                        },
                        shouldShowDocIcon(service) {
                            const cached = this.docLinkCache[service.name];
                            // Show icon if: not checked yet OR has a valid URL
                            return cached === undefined || cached !== null;
                        },
                        filterAndSort(items, isSort = true) {
                            const searchLower = this.search.trim().toLowerCase();
                            let filtered = Object.values(items);

                            if (searchLower !== '') {
                                filtered = filtered.filter(item => {
                                    return (item.name?.toLowerCase().includes(searchLower) ||
                                        item.description?.toLowerCase().includes(searchLower) ||
                                        item.slogan?.toLowerCase().includes(searchLower))
                                });
                            }

                            return isSort ? filtered.sort(sortFn) : filtered;
                        },
                        get filteredGitBasedApplications() {
                            if (this.gitBasedApplications.length === 0) {
                                return [];
                            }
                            return [
                                this.gitBasedApplications,
                            ].flatMap((items) => this.filterAndSort(items, false));
                        },
                        get filteredDockerBasedApplications() {
                            if (this.dockerBasedApplications.length === 0) {
                                return [];
                            }
                            return [
                                this.dockerBasedApplications,
                            ].flatMap((items) => this.filterAndSort(items, false));
                        },
                        get filteredDatabases() {
                            if (this.databases.length === 0) {
                                return [];
                            }
                            return [
                                this.databases,
                            ].flatMap((items) => this.filterAndSort(items, false));
                        },
                        get filteredServices() {
                            if (this.services.length === 0) {
                                return [];
                            }
                            const services = [
                                this.services,
                            ].flatMap((items) => this.filterAndSort(items, true));

                            if (this.selectedCategory === '') {
                                return services;
                            }

                            const selectedCategory = this.selectedCategory.toLowerCase();

                            return services.filter((service) => String(service.category ?? '')
                                .split(',')
                                .map((category) => category.trim().toLowerCase())
                                .includes(selectedCategory));
                        },
                        get visibleResourceCount() {
                            const applicationCount = this.filteredGitBasedApplications.length +
                                this.filteredDockerBasedApplications.length;

                            if (this.resourceType === 'applications') {
                                return applicationCount;
                            }

                            if (this.resourceType === 'databases') {
                                return this.filteredDatabases.length;
                            }

                            if (this.resourceType === 'services') {
                                return this.filteredServices.length;
                            }

                            return applicationCount + this.filteredDatabases.length + this.filteredServices.length;
                        }
                    }
                }
            </script>
        @endif
    </div>
    @if ($current_step === 'servers')
        <x-application.settings-section title="Select a server"
            description="Choose the machine that will host this resource." flush>
            @if ($onlyBuildServerAvailable)
                <x-callout type="warning" title="No deployment server" class="m-4">
                    Only build servers are available. Add or reconfigure a server before continuing.
                    <a class="font-medium underline" href="{{ route('server.index') }}" {{ wireNavigate() }}>Open
                        servers</a>
                </x-callout>
            @endif
            <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                @forelse($servers as $server)
                    <button type="button" wire:click="setServer({{ $server }})"
                        class="group flex min-h-14 w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-neutral-50 dark:hover:bg-white/[0.025]">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-dim">
                            <x-reicon name="servers" class="size-4" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[13px] font-semibold text-black dark:text-fg">
                                {{ $server->name }}
                            </span>
                            <span class="block truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                {{ $server->description ?: $server->ip }}
                            </span>
                        </span>
                        <x-status-badge status="running" text="Ready" />
                    </button>
                @empty
                    @if ($buildServers?->isEmpty() && ! $onlyBuildServerAvailable)
                        <x-empty title="No available servers"
                            description="Validate a reachable server before creating this resource."
                            icon-name="servers" size="sm">
                            <x-slot:actions>
                                <a class="button" href="{{ route('server.index') }}" {{ wireNavigate() }}>Open
                                    servers</a>
                            </x-slot:actions>
                        </x-empty>
                    @endif
                @endforelse

                @foreach($buildServers ?? [] as $buildServer)
                    <div class="flex min-h-14 items-center gap-3 px-4 py-3 opacity-55">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-dim">
                            <x-reicon name="servers" class="size-4" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-[13px] font-semibold text-black dark:text-fg">{{ $buildServer->name }}</span>
                            <span class="block text-[11px] text-neutral-500 dark:text-fg-faint">Build-only servers
                                cannot host resources.</span>
                        </span>
                        <x-status-badge status="exited" text="Build only" />
                        <a href="{{ route('server.show', ['server_uuid' => $buildServer->uuid]) }}"
                            {{ wireNavigate() }} class="button">Settings</a>
                    </div>
                @endforeach
            </div>
        </x-application.settings-section>
    @endif
    @if ($current_step === 'destinations')
        <x-application.settings-section title="Select a destination"
            description="Destinations separate resources by Docker network. Use the default destination when unsure."
            flush>
            <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                @if ($server->isSwarm())
                    @foreach ($swarmDockers as $swarmDocker)
                        <button type="button" wire:click="setDestination('{{ $swarmDocker->uuid }}')"
                            class="group flex min-h-14 w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-neutral-50 dark:hover:bg-white/[0.025]">
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-dim">
                                <x-reicon name="destinations" class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-[13px] font-semibold text-black dark:text-fg">{{ $swarmDocker->name }}</span>
                                <span class="block text-[11px] text-neutral-500 dark:text-fg-faint">Docker Swarm
                                    destination</span>
                            </span>
                            <x-deprecated-badge />
                        </button>
                    @endforeach
                @else
                    @foreach ($standaloneDockers as $standaloneDocker)
                        <button type="button" wire:click="setDestination('{{ $standaloneDocker->uuid }}')"
                            class="group flex min-h-14 w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-neutral-50 dark:hover:bg-white/[0.025]">
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-dim">
                                <x-reicon name="destinations" class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-[13px] font-semibold text-black dark:text-fg">{{ $standaloneDocker->name }}</span>
                                <span class="block truncate text-[11px] text-neutral-500 dark:text-fg-faint">Network:
                                    {{ $standaloneDocker->network }}</span>
                            </span>
                            <x-status-badge status="running" text="Standalone Docker" />
                        </button>
                    @endforeach
                @endif
            </div>
        </x-application.settings-section>
    @endif
    @if ($current_step === 'select-postgresql-type')
        <div x-data="{ selecting: false }">
            <div class="mb-4">
                <h2 class="text-[15px]! font-semibold!">Select a PostgreSQL image</h2>
                <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">Use PostgreSQL 18 unless the workload
                    needs bundled extensions.</p>
            </div>
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                <div class="group relative flex min-h-24 items-center gap-3 rounded-[10px] border border-neutral-200 bg-white p-4 transition-colors hover:border-neutral-300 hover:bg-neutral-50 dark:border-white/[0.07] dark:bg-surface dark:hover:border-white/[0.12] dark:hover:bg-white/[0.035]"
                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }"
                    x-on:click="!selecting && (selecting = true, $wire.setPostgresqlType('postgres:18-alpine'))"
                    :disabled="selecting">
                    <div class="flex flex-col">
                        <div class="text-[13px] font-semibold text-black dark:text-fg">PostgreSQL 18 <span
                                class="ml-1 rounded-full bg-coollabs/10 px-2 py-0.5 text-[10px] font-medium text-coollabs dark:bg-warning/15 dark:text-warning">Default</span>
                        </div>
                        <div class="mt-1 pr-8 text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">
                            PostgreSQL is a powerful, open-source object-relational database system (no extensions).
                        </div>
                    </div>
                    <a href="https://hub.docker.com/_/postgres/" target="_blank"
                        @click.stop
                        class="absolute top-2 right-2 flex size-7 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                        title="View documentation">
                        <svg class="w-4 h-4 text-neutral-600 dark:text-neutral-400" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </a>
                </div>
                <div class="group relative flex min-h-24 items-center gap-3 rounded-[10px] border border-neutral-200 bg-white p-4 transition-colors hover:border-neutral-300 hover:bg-neutral-50 dark:border-white/[0.07] dark:bg-surface dark:hover:border-white/[0.12] dark:hover:bg-white/[0.035]"
                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }"
                    x-on:click="!selecting && (selecting = true, $wire.setPostgresqlType('postgres:17-alpine'))"
                    :disabled="selecting">
                    <div class="flex flex-col">
                        <div class="text-[13px] font-semibold text-black dark:text-fg">PostgreSQL 17</div>
                        <div class="mt-1 pr-8 text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">
                            PostgreSQL is a powerful, open-source object-relational database system (no extensions).
                        </div>
                    </div>
                    <a href="https://hub.docker.com/_/postgres/" target="_blank"
                        @click.stop
                        class="absolute top-2 right-2 flex size-7 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                        title="View documentation">
                        <svg class="w-4 h-4 text-neutral-600 dark:text-neutral-400" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </a>
                </div>
                <div class="group relative flex min-h-24 items-center gap-3 rounded-[10px] border border-neutral-200 bg-white p-4 transition-colors hover:border-neutral-300 hover:bg-neutral-50 dark:border-white/[0.07] dark:bg-surface dark:hover:border-white/[0.12] dark:hover:bg-white/[0.035]"
                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }"
                    x-on:click="!selecting && (selecting = true, $wire.setPostgresqlType('postgres:16-alpine'))"
                    :disabled="selecting">
                    <div class="flex flex-col">
                        <div class="text-[13px] font-semibold text-black dark:text-fg">PostgreSQL 16</div>
                        <div class="mt-1 pr-8 text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">
                            PostgreSQL is a powerful, open-source object-relational database system (no extensions).
                        </div>
                    </div>
                    <a href="https://hub.docker.com/_/postgres/" target="_blank"
                        @click.stop
                        class="absolute top-2 right-2 flex size-7 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                        title="View documentation">
                        <svg class="w-4 h-4 text-neutral-600 dark:text-neutral-400" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </a>
                </div>
                <div class="group relative flex min-h-24 items-center gap-3 rounded-[10px] border border-neutral-200 bg-white p-4 transition-colors hover:border-neutral-300 hover:bg-neutral-50 dark:border-white/[0.07] dark:bg-surface dark:hover:border-white/[0.12] dark:hover:bg-white/[0.035]"
                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }"
                    x-on:click="!selecting && (selecting = true, $wire.setPostgresqlType('supabase/postgres:17.4.1.032'))"
                    :disabled="selecting">
                    <div class="flex flex-col">
                        <div class="text-[13px] font-semibold text-black dark:text-fg">Supabase PostgreSQL</div>
                        <div class="mt-1 pr-8 text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">
                            Supabase is a modern, open-source alternative to PostgreSQL with lots of extensions.
                        </div>
                    </div>
                    <a href="https://github.com/supabase/postgres" target="_blank"
                        @click.stop
                        class="absolute top-2 right-2 flex size-7 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                        title="View documentation">
                        <svg class="w-4 h-4 text-neutral-600 dark:text-neutral-400" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </a>
                </div>
                <div class="group relative flex min-h-24 items-center gap-3 rounded-[10px] border border-neutral-200 bg-white p-4 transition-colors hover:border-neutral-300 hover:bg-neutral-50 dark:border-white/[0.07] dark:bg-surface dark:hover:border-white/[0.12] dark:hover:bg-white/[0.035]"
                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }"
                    x-on:click="!selecting && (selecting = true, $wire.setPostgresqlType('postgis/postgis:17-3.5-alpine'))"
                    :disabled="selecting">
                    <div class="flex flex-col">
                        <div class="text-[13px] font-semibold text-black dark:text-fg">PostGIS <span
                                class="ml-1 text-[10px] font-medium text-amber-600 dark:text-amber-300">AMD only</span>
                        </div>
                        <div class="mt-1 pr-8 text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">
                            PostGIS is a PostgreSQL extension for geographic objects.
                        </div>
                    </div>
                    <a href="https://github.com/postgis/docker-postgis" target="_blank"
                        @click.stop
                        class="absolute top-2 right-2 flex size-7 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                        title="View documentation">
                        <svg class="w-4 h-4 text-neutral-600 dark:text-neutral-400" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </a>
                </div>
                <div class="group relative flex min-h-24 items-center gap-3 rounded-[10px] border border-neutral-200 bg-white p-4 transition-colors hover:border-neutral-300 hover:bg-neutral-50 dark:border-white/[0.07] dark:bg-surface dark:hover:border-white/[0.12] dark:hover:bg-white/[0.035]"
                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }"
                    x-on:click="!selecting && (selecting = true, $wire.setPostgresqlType('pgvector/pgvector:pg18'))"
                    :disabled="selecting">
                    <div class="flex flex-col">
                        <div class="text-[13px] font-semibold text-black dark:text-fg">PGVector 18</div>
                        <div class="mt-1 pr-8 text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">
                            PGVector is a PostgreSQL extension for vector data types.
                        </div>
                    </div>
                    <a href="https://github.com/pgvector/pgvector" target="_blank"
                        @click.stop
                        class="absolute top-2 right-2 flex size-7 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                        title="View documentation">
                        <svg class="w-4 h-4 text-neutral-600 dark:text-neutral-400" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </a>
                </div>
                <div class="group relative flex min-h-24 items-center gap-3 rounded-[10px] border border-neutral-200 bg-white p-4 transition-colors hover:border-neutral-300 hover:bg-neutral-50 dark:border-white/[0.07] dark:bg-surface dark:hover:border-white/[0.12] dark:hover:bg-white/[0.035]"
                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }"
                    x-on:click="!selecting && (selecting = true, $wire.setPostgresqlType('pgvector/pgvector:pg17'))"
                    :disabled="selecting">
                    <div class="flex flex-col">
                        <div class="text-[13px] font-semibold text-black dark:text-fg">PGVector 17</div>
                        <div class="mt-1 pr-8 text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">
                            PGVector is a PostgreSQL extension for vector data types.
                        </div>
                    </div>
                    <a href="https://github.com/pgvector/pgvector" target="_blank"
                        @click.stop
                        class="absolute top-2 right-2 flex size-7 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                        title="View documentation">
                        <svg class="w-4 h-4 text-neutral-600 dark:text-neutral-400" fill="none"
                            stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    @endif
    @if ($current_step === 'existing-postgresql')
        <x-application.settings-section title="Connect an existing PostgreSQL database"
            description="Provide the connection URL for the database Coolify should use.">
            <form wire:submit="addExistingPostgresql" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="min-w-0 flex-1">
                    <x-forms.input placeholder="postgres://username:password@database:5432" label="Database URL"
                        id="existingPostgresqlUrl" />
                </div>
                <x-forms.button type="submit">Add database</x-forms.button>
            </form>
        </x-application.settings-section>
    @endif
</div>
