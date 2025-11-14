<div x-data x-init="$wire.loadServers">
    <div class="flex flex-col gap-4 lg:flex-row ">
        <h1>New Resource</h1>
        <div class="w-full pb-4 lg:w-96 lg:pb-0">
            <x-forms.select wire:model.live="selectedEnvironment">
                @foreach ($environments as $environment)
                    <option value="{{ $environment->name }}">Environment: {{ $environment->name }}</option>
                @endforeach
            </x-forms.select>
        </div>
    </div>
    <div class="pb-4">Deploy resources, like Applications, Databases, Services...</div>
    <div x-data="searchResources()">
        @if ($current_step === 'type')
            <div x-init="window.addEventListener('scroll', () => isSticky = window.pageYOffset > 100)" class="sticky z-10 top-10 py-2 bg-white/95 dark:bg-base/95 backdrop-blur-sm">
                <div class="flex gap-2 items-start">
                    <input autocomplete="off" x-ref="searchInput" class="input-sticky flex-1"
                        :class="{ 'input-sticky-active': isSticky }" x-model="search" placeholder="Type / to search..."
                        @keydown.window.slash.prevent="$refs.searchInput.focus()">
                    <!-- Category Filter Dropdown -->
                    <div class="relative" x-data="{ openCategoryDropdown: false, categorySearch: '' }" @click.outside="openCategoryDropdown = false">
                        <!-- Loading/Disabled State -->
                        <div x-show="loading || categories.length === 0"
                            class="flex items-center justify-between gap-2 py-1.5 px-3 w-64 text-sm rounded-sm border-0 ring-2 ring-inset ring-neutral-200 dark:ring-coolgray-300 bg-neutral-100 dark:bg-coolgray-200 cursor-not-allowed whitespace-nowrap opacity-50">
                            <span class="text-sm text-neutral-400 dark:text-neutral-600">Filter by category</span>
                            <svg class="w-4 h-4 text-neutral-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </div>
                        <!-- Active State -->
                        <div x-show="!loading && categories.length > 0"
                            @click="openCategoryDropdown = !openCategoryDropdown; $nextTick(() => { if (openCategoryDropdown) $refs.categorySearchInput.focus() })"
                            class="flex items-center justify-between gap-2 py-1.5 px-3 w-64 text-sm rounded-sm border-0 ring-2 ring-inset ring-neutral-200 dark:ring-coolgray-300 bg-white dark:bg-coolgray-100 cursor-pointer hover:ring-coolgray-400 transition-all whitespace-nowrap">
                            <span class="text-sm truncate" x-text="selectedCategory === '' ? 'Filter by category' : selectedCategory" :class="selectedCategory === '' ? 'text-neutral-400 dark:text-neutral-600' : 'capitalize text-black dark:text-white'"></span>
                            <svg class="w-4 h-4 transition-transform text-neutral-400 shrink-0" :class="{ 'rotate-180': openCategoryDropdown }" fill="none"
                                stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </div>
                        <!-- Dropdown Menu -->
                        <div x-show="openCategoryDropdown" x-transition
                            class="absolute z-50 w-full mt-1 bg-white dark:bg-coolgray-100 border border-neutral-300 dark:border-coolgray-400 rounded shadow-lg overflow-hidden">
                            <div class="sticky top-0 p-2 bg-white dark:bg-coolgray-100 border-b border-neutral-300 dark:border-coolgray-400">
                                <input type="text" x-ref="categorySearchInput" x-model="categorySearch" placeholder="Search categories..."
                                    class="w-full px-2 py-1 text-sm rounded border border-neutral-300 dark:border-coolgray-400 bg-white dark:bg-coolgray-200 focus:outline-none focus:ring-2 focus:ring-coolgray-400"
                                    @click.stop>
                            </div>
                            <div class="max-h-60 overflow-auto scrollbar">
                                <div @click="selectedCategory = ''; categorySearch = ''; openCategoryDropdown = false"
                                    class="px-3 py-2 cursor-pointer hover:bg-neutral-100 dark:hover:bg-coolgray-200"
                                    :class="{ 'bg-neutral-50 dark:bg-coolgray-300': selectedCategory === '' }">
                                    <span class="text-sm">All Categories</span>
                                </div>
                                <template x-for="category in categories.filter(cat => categorySearch === '' || cat.toLowerCase().includes(categorySearch.toLowerCase()))" :key="category">
                                    <div @click="selectedCategory = category; categorySearch = ''; openCategoryDropdown = false"
                                        class="px-3 py-2 cursor-pointer hover:bg-neutral-100 dark:hover:bg-coolgray-200 capitalize"
                                        :class="{ 'bg-neutral-50 dark:bg-coolgray-300': selectedCategory === category }">
                                        <span class="text-sm" x-text="category"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div x-show="loading">Loading...</div>
            <div x-show="!loading" class="flex flex-col gap-4 py-4">
                <h2 x-show="filteredGitBasedApplications.length > 0">Applications</h2>
                <h4 x-show="filteredGitBasedApplications.length > 0">Git Based</h4>
                <div x-show="filteredGitBasedApplications.length > 0"
                    class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-1">
                    <template x-for="application in filteredGitBasedApplications" :key="application.name">
                        <div x-on:click='setType(application.id)'
                            :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }">
                            <x-resource-view>
                                <x-slot:title><span x-text="application.name"></span></x-slot>
                                    <x-slot:description>
                                        <span x-html="window.sanitizeHTML(application.description)"></span>
                                        </x-slot>
                                        <x-slot:logo>
                                            <img class="w-full h-full p-2 transition-all duration-200 dark:bg-white/10 bg-black/10 object-contain"
                                                :src="application.logo">
                                        </x-slot:logo>
                            </x-resource-view>
                        </div>
                    </template>
                </div>
                <h4 x-show="filteredDockerBasedApplications.length > 0">Docker Based</h4>
                <div x-show="filteredDockerBasedApplications.length > 0"
                    class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-3">
                    <template x-for="application in filteredDockerBasedApplications" :key="application.name">
                        <div x-on:click="setType(application.id)"
                            :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }">
                            <x-resource-view>
                                <x-slot:title><span x-text="application.name"></span></x-slot>
                                    <x-slot:description><span x-text="application.description"></span></x-slot>
                                        <x-slot:logo> <img
                                                class="w-full h-full p-2 transition-all duration-200 dark:bg-white/10 bg-black/10 object-contain"
                                                :src="application.logo"></x-slot>
                            </x-resource-view>
                        </div>
                    </template>
                </div>
                <h2 x-show="filteredDatabases.length > 0">Databases</h2>
                <div x-show="filteredDatabases.length > 0"
                    class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-2">
                    <template x-for="database in filteredDatabases" :key="database.id">
                        <div x-on:click="setType(database.id)"
                            :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }">
                            <x-resource-view>
                                <x-slot:title><span x-text="database.name"></span></x-slot>
                                    <x-slot:description><span x-text="database.description"></span></x-slot>
                                        <x-slot:logo>
                                            <span x-show="database.logo">
                                                <span x-html="database.logo"></span>
                                            </span>
                                            </x-slot>
                            </x-resource-view>
                        </div>
                    </template>
                </div>
                <div x-show="filteredServices.length > 0">
                    <div class="flex items-center gap-4" x-init="loadResources">
                        <h2>Services</h2>
                        <x-forms.button x-on:click="loadResources">Reload List</x-forms.button>
                    </div>
                    <div class="py-4 text-xs">Trademarks Policy: The respective trademarks mentioned here are owned by
                        the
                        respective
                        companies, and use of them does not imply any affiliation or endorsement.<br>Find more services
                        <a class="dark:text-white underline" target="_blank"
                            href="https://coolify.io/docs/services/overview">here</a>.
                    </div>

                    <div class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-2">
                        <template x-for="service in filteredServices" :key="service.name">
                            <div x-on:click="setType('one-click-service-' + service.name)"
                                :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }">
                                <x-resource-view>
                                    <x-slot:title>
                                        <template x-if="service.name">
                                            <span x-text="service.name"></span>
                                        </template>
                                        </x-slot>
                                        <x-slot:description>
                                            <template x-if="service.slogan">
                                                <span x-text="service.slogan"></span>
                                            </template>
                                            </x-slot>
                                            <x-slot:logo>
                                                <template x-if="service.logo">
                                                    <img class="w-full h-full p-2 transition-all duration-200 dark:bg-white/10 bg-black/10 object-contain"
                                                        :src='service.logo'
                                                        x-on:error.window="$event.target.src = service.logo_github_url"
                                                        onerror="this.onerror=null; this.src=this.getAttribute('data-fallback');"
                                                        x-on:error="$event.target.src = '/coolify-logo.svg'"
                                                        :data-fallback='service.logo_github_url' />
                                                </template>
                                            </x-slot:logo>
                                            <x-slot:documentation>
                                                <template x-if="service.documentation">
                                                    <div class="flex items-center px-2" title="Read the documentation.">
                                                        <a class="p-2 rounded-sm hover:bg-gray-100 dark:hover:bg-coolgray-200 hover:no-underline dark:group-hover:text-white text-neutral-600"
                                                            onclick="event.stopPropagation()" :href="service.documentation"
                                                            target="_blank">
                                                            Docs
                                                        </a>
                                                    </div>
                                                </template>
                                            </x-slot:documentation>
                                </x-resource-view>
                            </div>
                        </template>
                    </div>
                </div>
                <div
                    x-show="filteredGitBasedApplications.length === 0 && filteredDockerBasedApplications.length === 0 && filteredDatabases.length === 0 && filteredServices.length === 0 && loading === false">
                    <div>No resources found.</div>
                </div>
            </div>
            <script>
                function sortFn(a, b) {
                    return a.name.localeCompare(b.name)
                }

                function searchResources() {
                    return {
                        search: '',
                        selectedCategory: '',
                        categories: [],
                        loading: false,
                        isSticky: false,
                        selecting: false,
                        services: [],
                        gitBasedApplications: [],
                        dockerBasedApplications: [],
                        databases: [],
                        setType(type) {
                            if (this.selecting) return;
                            this.selecting = true;
                            this.$wire.setType(type);
                        },
                        async loadResources() {
                            this.loading = true;
                            const {
                                services,
                                categories,
                                gitBasedApplications,
                                dockerBasedApplications,
                                databases
                            } = await this.$wire.loadServices();
                            this.services = services;
                            this.categories = categories || [];
                            this.gitBasedApplications = gitBasedApplications;
                            this.dockerBasedApplications = dockerBasedApplications;
                            this.databases = databases;
                            this.loading = false;
                            this.$nextTick(() => {
                                this.$refs.searchInput.focus();
                            });
                        },
                        filterAndSort(items, isSort = true) {
                            const searchLower = this.search.trim().toLowerCase();
                            let filtered = Object.values(items);

                            // Filter by category if selected
                            if (this.selectedCategory !== '') {
                                const selectedCategoryLower = this.selectedCategory.toLowerCase();
                                filtered = filtered.filter(item => {
                                    if (!item.category) return false;
                                    // Handle comma-separated categories
                                    const categories = item.category.includes(',')
                                        ? item.category.split(',').map(c => c.trim().toLowerCase())
                                        : [item.category.toLowerCase()];
                                    return categories.includes(selectedCategoryLower);
                                });
                            }

                            // Filter by search term
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
                            return [
                                this.services,
                            ].flatMap((items) => this.filterAndSort(items, true));
                        }
                    }
                }
            </script>
        @endif
    </div>
    @if ($current_step === 'servers')
        <h2>Select a server</h2>
        <div class="pb-5"></div>
        <div class="flex flex-col justify-center gap-4 text-left xl:flex-row xl:flex-wrap">
            @if ($onlyBuildServerAvailable)
                <div> Only build servers are available, you need at least one server that is not set as build
                    server. <a class="underline dark:text-white" href="/servers">
                        Go to servers page
                    </a> </div>
            @else
                @forelse($servers as $server)
                    <div class="w-full box group" wire:click="setServer({{ $server }})">
                        <div class="flex flex-col mx-6">
                            <div class="box-title">
                                {{ $server->name }}
                            </div>
                            <div class="box-description">
                                {{ $server->description }}
                            </div>
                        </div>
                    </div>
                @empty
                    <div>

                        <div>No validated & reachable servers found. <a class="underline dark:text-white" href="/servers">
                                Go to servers page
                            </a></div>
                    </div>
                @endforelse
            @endif
        </div>
    @endif
    @if ($current_step === 'destinations')
        <h2>Select a destination</h2>
        <div class="pb-4">Destinations are used to segregate resources by network. If you are unsure, select the
            default
            Standalone Docker (coolify).</div>
        <div class="flex flex-col justify-center gap-4 text-left xl:flex-row xl:flex-wrap">
            @if ($server->isSwarm())
                @foreach ($swarmDockers as $swarmDocker)
                    <div class="w-full box group" wire:click="setDestination('{{ $swarmDocker->uuid }}')">
                        <div class="flex flex-col mx-6">
                            <div class="font-bold dark:group-hover:text-white">
                                Swarm Docker <span class="text-xs">({{ $swarmDocker->name }})</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            @else
                @foreach ($standaloneDockers as $standaloneDocker)
                    <div class="w-full box group" wire:click="setDestination('{{ $standaloneDocker->uuid }}')">
                        <div class="flex flex-col mx-6">
                            <div class="box-title">
                                Standalone Docker <span class="text-xs">({{ $standaloneDocker->name }})</span>
                            </div>
                            <div class="box-description">
                                Network: {{ $standaloneDocker->network }}</div>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    @endif
    @if ($current_step === 'select-postgresql-type')
        <div x-data="{ selecting: false }">
            <h2>Select a Postgresql type</h2>
            <div>If you need extra extensions, you can select Supabase PostgreSQL (or others), otherwise select
                PostgreSQL
                17 (default).</div>
            <div class="flex flex-col gap-6 pt-8">
                <div class="gap-2 border border-transparent box-without-bg dark:bg-coolgray-100 bg-white dark:hover:text-neutral-400 dark:hover:bg-coollabs group flex"
                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }"
                    x-on:click="!selecting && (selecting = true, $wire.setPostgresqlType('postgres:17-alpine'))"
                    :disabled="selecting">
                    <div class="flex flex-col">
                        <div class="box-title">PostgreSQL 17 (default)</div>
                        <div class="box-description">
                            PostgreSQL is a powerful, open-source object-relational database system (no extensions).
                        </div>
                    </div>
                    <div class="flex-1"></div>

                    <div class="flex items-center px-2" title="Read the documentation.">
                        <a class="p-2 hover:underline dark:group-hover:text-white dark:text-white text-neutral-6000"
                            onclick="event.stopPropagation()" href="https://hub.docker.com/_/postgres/" target="_blank">
                            Documentation
                        </a>
                    </div>
                </div>
                <div class="gap-2 border border-transparent box-without-bg dark:bg-coolgray-100 bg-white dark:hover:text-neutral-400 dark:hover:bg-coollabs group flex"
                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }"
                    x-on:click="!selecting && (selecting = true, $wire.setPostgresqlType('supabase/postgres:17.4.1.032'))"
                    :disabled="selecting">
                    <div class="flex flex-col">
                        <div class="box-title">Supabase PostgreSQL (with extensions)</div>
                        <div class="box-description">
                            Supabase is a modern, open-source alternative to PostgreSQL with lots of extensions.
                        </div>
                    </div>
                    <div class="flex-1"></div>
                    <div class="flex items-center px-2" title="Read the documentation.">
                        <a class="p-2 hover:underline dark:group-hover:text-white dark:text-white text-neutral-600"
                            onclick="event.stopPropagation()" href="https://github.com/supabase/postgres" target="_blank">
                            Documentation
                        </a>
                    </div>
                </div>
                <div class="gap-2 border border-transparent box-without-bg dark:bg-coolgray-100 bg-white dark:hover:text-neutral-400 dark:hover:bg-coollabs group flex"
                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }"
                    x-on:click="!selecting && (selecting = true, $wire.setPostgresqlType('postgis/postgis:17-3.5-alpine'))"
                    :disabled="selecting">
                    <div class="flex flex-col">
                        <div class="box-title">PostGIS (AMD only)</div>
                        <div class="box-description">
                            PostGIS is a PostgreSQL extension for geographic objects.
                        </div>
                    </div>
                    <div class="flex-1"></div>
                    <div class="flex items-center px-2" title="Read the documentation.">
                        <a class="p-2 hover:underline dark:group-hover:text-white dark:text-white text-neutral-600"
                            onclick="event.stopPropagation()" href="https://github.com/postgis/docker-postgis"
                            target="_blank">
                            Documentation
                        </a>
                    </div>
                </div>
                <div class="gap-2 border border-transparent box-without-bg dark:bg-coolgray-100 bg-white dark:hover:text-neutral-400 dark:hover:bg-coollabs group flex"
                    :class="{ 'cursor-pointer': !selecting, 'cursor-not-allowed opacity-50': selecting }"
                    x-on:click="!selecting && (selecting = true, $wire.setPostgresqlType('pgvector/pgvector:pg17'))"
                    :disabled="selecting">
                    <div class="flex flex-col">
                        <div class="box-title">PGVector (17)</div>
                        <div class="box-description">
                            PGVector is a PostgreSQL extension for vector data types.
                        </div>
                    </div>
                    <div class="flex-1"></div>

                    <div class="flex items-center px-2" title="Read the documentation.">
                        <a class="p-2 hover:underline dark:group-hover:text-white dark:text-white text-neutral-600"
                            onclick="event.stopPropagation()" href="https://github.com/pgvector/pgvector" target="_blank">
                            Documentation
                        </a>
                    </div>
                </div>
            </div>
        </div>
    @endif
    @if ($current_step === 'existing-postgresql')
        <form wire:submit='addExistingPostgresql' class="flex items-end gap-4">
            <x-forms.input placeholder="postgres://username:password@database:5432" label="Database URL"
                id="existingPostgresqlUrl" />
            <x-forms.button type="submit">Add Database</x-forms.button>
        </form>
    @endif
</div>