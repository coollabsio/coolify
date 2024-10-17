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
            <div class="sticky top-0 z-50 py-2">
                <input autocomplete="off" x-ref="searchInput" class="input w-full" x-model="search"
                    placeholder="Type / to search..." @keydown.window.slash.prevent="$refs.searchInput.focus()">
            </div>
            <div x-show="loading">Loading...</div>
            <div x-show="!loading" class="flex flex-col gap-4 py-4">
                <h2 x-show="filteredGitBasedApplications.length > 0">Applications</h2>
                <h4 x-show="filteredGitBasedApplications.length > 0">Git Based</h4>
                <div x-show="filteredGitBasedApplications.length > 0"
                    class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-1">
                    <template x-for="application in filteredGitBasedApplications" :key="application.name">
                        <div x-on:click='setType(application.id)'>
                            <x-resource-view>
                                <x-slot:title><span x-text="application.name"></span></x-slot>
                                <x-slot:description>
                                    <span x-html="application.description"></span>
                                </x-slot>
                                <x-slot:logo>
                                    <img class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100 "
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
                        <div x-on:click="setType(application.id)">
                            <x-resource-view>
                                <x-slot:title><span x-text="application.name"></span></x-slot>
                                <x-slot:description><span x-text="application.description"></span></x-slot>
                                <x-slot:logo> <img
                                        class="w-[4.5rem]
                            aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100 "
                                        :src="application.logo"></x-slot>
                            </x-resource-view>
                        </div>
                    </template>
                </div>
                <h2 x-show="filteredDatabases.length > 0">Databases</h2>
                <div x-show="filteredDatabases.length > 0"
                    class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-2">
                    <template x-for="database in filteredDatabases" :key="database.id">
                        <div x-on:click="setType(database.id)">
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
                    <div class="pb-4 text-xs">Trademarks Policy: The respective trademarks mentioned here are owned by
                        the
                        respective
                        companies, and use of them does not imply any affiliation or endorsement.<br>Find more services
                        <a class="dark:text-white underline" target="_blank"
                            href="https://coolify.io/docs/services">here</a>.
                    </div>

                    <div class="grid justify-start grid-cols-1 gap-4 text-left xl:grid-cols-2">
                        <template x-for="service in filteredServices" :key="service.name">
                            <div x-on:click="setType('one-click-service-' + service.name)">
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
                                            <img class="w-[4.5rem] aspect-square h-[4.5rem] p-2 transition-all duration-200 opacity-30 grayscale group-hover:grayscale-0 group-hover:opacity-100"
                                                :src='service.logo'>
                                        </template>
                                    </x-slot:logo>
                                    <x-slot:documentation>
                                        <template x-if="service.documentation">
                                            <div class="flex items-center px-2" title="Read the documentation.">
                                                <a class="p-2 rounded hover:bg-coolgray-200 hover:no-underline group-hover:dark:text-white text-neutral-600"
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
                        loading: false,
                        services: [],
                        gitBasedApplications: [],
                        dockerBasedApplications: [],
                        databases: [],
                        setType(type) {
                            this.$wire.setType(type);
                        },
                        async loadResources() {
                            this.loading = true;
                            const {
                                services,
                                gitBasedApplications,
                                dockerBasedApplications,
                                databases
                            } = await this.$wire.loadServices();
                            this.services = services;
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

                            if (searchLower === '') {
                                return isSort ? Object.values(items).sort(sortFn) : Object.values(items);
                            }
                            const filtered = Object.values(items).filter(item => {
                                return (item.name?.toLowerCase().includes(searchLower) ||
                                    item.description?.toLowerCase().includes(searchLower))
                            })
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
            @forelse($servers as $server)
                <div class="w-full box group" wire:click="setServer({{ $server }})">
                    <div class="flex flex-col mx-6">
                        <div class="box-title">
                            {{ $server->name }}
                        </div>
                        <div class="box-description">
                            {{ $server->description }}</div>
                    </div>
                </div>
            @empty
                <div>
                    <div>No validated & reachable servers found. <a class="underline dark:text-white" href="/servers">
                            Go to servers page
                        </a></div>
                </div>
            @endforelse
        </div>
        {{-- @if ($isDatabase)
                <div class="text-center">Swarm clusters are excluded from this type of resource at the moment. It will
                    be activated soon. Stay tuned.</div>
            @endif --}}
    @endif
    @if ($current_step === 'destinations')
        <h2>Select a destination</h2>
        <div>Destinations are used to segregate resources by network. If you are unsure, select the default
            Standalone Docker (coolify).</div>
        <div class="flex flex-col justify-center gap-4 text-left xl:flex-row xl:flex-wrap">
            @if ($server->isSwarm())
                @foreach ($swarmDockers as $swarmDocker)
                    <div class="w-full box group" wire:click="setDestination('{{ $swarmDocker->uuid }}')">
                        <div class="flex flex-col mx-6">
                            <div class="font-bold group-hover:dark:text-white">
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
        <h2>Select a Postgresql type</h2>
        <div>If you need extra extensions, you can select Supabase PostgreSQL (or others), otherwise select PostgreSQL
            16 (default).</div>
        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-2">
                <div class="gap-2 border border-transparent cursor-pointer box-without-bg dark:bg-coolgray-100 bg-white dark:hover:text-neutral-400 dark:hover:bg-coollabs group flex"
                    wire:click="setPostgresqlType('postgres:16-alpine')">
                    <div class="flex flex-col">
                        <div class="box-title">PostgreSQL 16 (default)</div>
                        <div class="box-description">
                            PostgreSQL is a powerful, open-source object-relational database system (no extensions).
                        </div>
                    </div>
                    <div class="flex-1"></div>

                    <div class="flex items-center px-2" title="Read the documentation.">
                        <a class="p-2 hover:underline group-hover:dark:text-white dark:text-white text-neutral-6000"
                            onclick="event.stopPropagation()" href="https://hub.docker.com/_/postgres/"
                            target="_blank">
                            Documentation
                        </a>
                    </div>
                </div>
                <div class="gap-2 border border-transparent cursor-pointer box-without-bg dark:bg-coolgray-100 bg-white dark:hover:text-neutral-400 dark:hover:bg-coollabs group flex"
                    wire:click="setPostgresqlType('supabase/postgres:15.6.1.113')">
                    <div class="flex flex-col">
                        <div class="box-title">Supabase PostgreSQL (with extensions)</div>
                        <div class="box-description">
                            Supabase is a modern, open-source alternative to PostgreSQL with lots of extensions.
                        </div>
                    </div>
                    <div class="flex-1"></div>
                    <div class="flex items-center px-2" title="Read the documentation.">
                        <a class="p-2 hover:underline group-hover:dark:text-white dark:text-white text-neutral-600"
                            onclick="event.stopPropagation()" href="https://github.com/supabase/postgres"
                            target="_blank">
                            Documentation
                        </a>
                    </div>
                </div>
                <div class="gap-2 border border-transparent cursor-pointer box-without-bg dark:bg-coolgray-100 bg-white dark:hover:text-neutral-400 dark:hover:bg-coollabs group flex"
                    wire:click="setPostgresqlType('postgis/postgis')">
                    <div class="flex flex-col">
                        <div class="box-title">PostGIS</div>
                        <div class="box-description">
                            PostGIS is a PostgreSQL extension for geographic objects.
                        </div>
                    </div>
                    <div class="flex-1"></div>
                    <div class="flex items-center px-2" title="Read the documentation.">
                        <a class="p-2 hover:underline group-hover:dark:text-white dark:text-white text-neutral-600"
                            onclick="event.stopPropagation()" href="https://github.com/postgis/docker-postgis"
                            target="_blank">
                            Documentation
                        </a>
                    </div>
                </div>
                <div class="gap-2 border border-transparent cursor-pointer box-without-bg dark:bg-coolgray-100 bg-white dark:hover:text-neutral-400 dark:hover:bg-coollabs group flex"
                    wire:click="setPostgresqlType('pgvector/pgvector:pg16')">
                    <div class="flex flex-col">
                        <div class="box-title">PGVector (16)</div>
                        <div class="box-description">
                            PGVector is a PostgreSQL extension for vector data types.
                        </div>
                    </div>
                    <div class="flex-1"></div>

                    <div class="flex items-center px-2" title="Read the documentation.">
                        <a class="p-2 hover:underline group-hover:dark:text-white dark:text-white text-neutral-600"
                            onclick="event.stopPropagation()" href="https://github.com/pgvector/pgvector"
                            target="_blank">
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
