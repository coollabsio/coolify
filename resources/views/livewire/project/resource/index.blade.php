<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Resources | Coolify
    </x-slot>
    <div class="flex flex-col">
        <div class="flex min-w-0 flex-nowrap items-center gap-1">
            <h1>Resources</h1>
            @if ($environment->isEmpty())
                @can('createAnyResource')
                    <a class="button" {{ wireNavigate() }}
                        href="{{ route('project.clone-me', ['project_uuid' => data_get($project, 'uuid'), 'environment_uuid' => data_get($environment, 'uuid')]) }}">
                        Clone
                    </a>
                @endcan
            @else
                @can('createAnyResource')
                    <a href="{{ route('project.resource.create', ['project_uuid' => data_get($parameters, 'project_uuid'), 'environment_uuid' => data_get($environment, 'uuid')]) }}"
                        {{ wireNavigate() }} class="button">+
                        New</a>
                @endcan
                @can('createAnyResource')
                    <a class="button" {{ wireNavigate() }}
                        href="{{ route('project.clone-me', ['project_uuid' => data_get($project, 'uuid'), 'environment_uuid' => data_get($environment, 'uuid')]) }}">
                        Clone
                    </a>
                @endcan
            @endif
            @can('delete', $environment)
                <livewire:project.delete-environment :disabled="!$environment->isEmpty()" :environment_id="$environment->id" />
            @endcan
        </div>
        <nav class="flex pt-2 pb-6">
            <ol class="flex items-center">
                <li class="inline-flex items-center" x-data="{ projectOpen: false, toggle() { this.projectOpen = !this.projectOpen }, open() { this.projectOpen = true }, close() { this.projectOpen = false } }">
                    <div class="flex items-center relative" @mouseenter="open()" @mouseleave="close()">
                        <a class="text-xs truncate lg:text-sm hover:text-warning" {{ wireNavigate() }}
                            href="{{ route('project.show', ['project_uuid' => data_get($parameters, 'project_uuid')]) }}">
                            {{ $project->name }}</a>
                        <button type="button" @click.stop="toggle()" class="px-1 text-warning">
                            <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-90': projectOpen }"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="4" d="M9 5l7 7-7 7">
                                </path>
                            </svg>
                        </button>

                        <div x-show="projectOpen" @click.outside="close()"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                            class="absolute z-20 top-full mt-1 w-56 -ml-2 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-neutral-200 dark:border-coolgray-200 max-h-96 overflow-y-auto scrollbar">
                            @foreach ($allProjects as $proj)
                                <a href="{{ route('project.show', ['project_uuid' => $proj->uuid]) }}"
                                    class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200 {{ $proj->uuid === $project->uuid ? 'dark:text-warning font-semibold' : '' }}"
                                    title="{{ $proj->name }}">
                                    {{ $proj->name }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </li>
                <li class="inline-flex items-center" x-data="{ envOpen: false, activeEnv: null, envPositions: {}, closeTimeout: null, envTimeout: null, toggle() { this.envOpen = !this.envOpen; if (!this.envOpen) { this.activeEnv = null; } }, open() { clearTimeout(this.closeTimeout); this.envOpen = true }, close() { this.closeTimeout = setTimeout(() => { this.envOpen = false; this.activeEnv = null; }, 100) }, openEnv(id) { clearTimeout(this.closeTimeout); clearTimeout(this.envTimeout); this.activeEnv = id }, closeEnv() { this.envTimeout = setTimeout(() => { this.activeEnv = null; }, 100) } }">
                    <div class="flex items-center relative" @mouseenter="open()" @mouseleave="close()">
                        <a class="text-xs truncate lg:text-sm hover:text-warning" {{ wireNavigate() }}
                            href="{{ route('project.resource.index', ['project_uuid' => data_get($parameters, 'project_uuid'), 'environment_uuid' => $environment->uuid]) }}">
                            {{ $environment->name }}
                        </a>

                        <div x-show="envOpen" @click.outside="close()"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                            class="absolute z-20 top-full mt-1 left-0 sm:left-auto max-w-[calc(100vw-1rem)]"
                            x-init="$nextTick(() => { const rect = $el.getBoundingClientRect(); if (rect.right > window.innerWidth) { $el.style.left = 'auto';
                                    $el.style.right = '0'; } })">
                            <!-- Environment List -->
                            <div
                                class="relative w-48 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-neutral-200 dark:border-coolgray-200 max-h-96 overflow-y-auto scrollbar">
                                @foreach ($allEnvironments as $env)
                                    @php
                                        $envDatabases = collect()
                                            ->merge($env->postgresqls ?? collect())
                                            ->merge($env->redis ?? collect())
                                            ->merge($env->mongodbs ?? collect())
                                            ->merge($env->mysqls ?? collect())
                                            ->merge($env->mariadbs ?? collect())
                                            ->merge($env->keydbs ?? collect())
                                            ->merge($env->dragonflies ?? collect())
                                            ->merge($env->clickhouses ?? collect());
                                        $envResources = collect()
                                            ->merge($env->applications->map(fn($app) => ['type' => 'application', 'resource' => $app]))
                                            ->merge($envDatabases->map(fn($db) => ['type' => 'database', 'resource' => $db]))
                                            ->merge($env->services->map(fn($svc) => ['type' => 'service', 'resource' => $svc]))
                                            ->sortBy(fn($item) => strtolower($item['resource']->name));
                                    @endphp
                                    <div @mouseenter="openEnv('{{ $env->uuid }}'); envPositions['{{ $env->uuid }}'] = $el.offsetTop - ($el.closest('.overflow-y-auto')?.scrollTop || 0)"
                                        @mouseleave="closeEnv()">
                                        <a href="{{ route('project.resource.index', ['project_uuid' => data_get($parameters, 'project_uuid'), 'environment_uuid' => $env->uuid]) }}"
                                            {{ wireNavigate() }}
                                            class="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-neutral-100 dark:hover:bg-coolgray-200 {{ $env->uuid === $environment->uuid ? 'dark:text-warning font-semibold' : '' }}"
                                            title="{{ $env->name }}">
                                            <span class="truncate">{{ $env->name }}</span>
                                            @if ($envResources->count() > 0)
                                                <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="4" d="M9 5l7 7-7 7"></path>
                                                </svg>
                                            @endif
                                        </a>
                                    </div>
                                @endforeach
                                <div class="border-t border-neutral-200 dark:border-coolgray-200 mt-1 pt-1">
                                    <a href="{{ route('project.show', ['project_uuid' => data_get($parameters, 'project_uuid')]) }}"
                                        {{ wireNavigate() }}
                                        class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-neutral-100 dark:hover:bg-coolgray-200">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                            </path>
                                        </svg>
                                        Create / Edit
                                    </a>
                                </div>
                            </div>

                            <!-- Resources Sub-dropdown (2nd level) -->
                            @foreach ($allEnvironments as $env)
                                @php
                                    $envDatabases = collect()
                                        ->merge($env->postgresqls ?? collect())
                                        ->merge($env->redis ?? collect())
                                        ->merge($env->mongodbs ?? collect())
                                        ->merge($env->mysqls ?? collect())
                                        ->merge($env->mariadbs ?? collect())
                                        ->merge($env->keydbs ?? collect())
                                        ->merge($env->dragonflies ?? collect())
                                        ->merge($env->clickhouses ?? collect());
                                    $envResources = collect()
                                        ->merge($env->applications->map(fn($app) => ['type' => 'application', 'resource' => $app]))
                                        ->merge($envDatabases->map(fn($db) => ['type' => 'database', 'resource' => $db]))
                                        ->merge($env->services->map(fn($svc) => ['type' => 'service', 'resource' => $svc]));
                                @endphp
                                @if ($envResources->count() > 0)
                                    <div x-show="activeEnv === '{{ $env->uuid }}'" x-cloak
                                        x-transition:enter="transition ease-out duration-150"
                                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                        @mouseenter="openEnv('{{ $env->uuid }}')" @mouseleave="closeEnv()"
                                        :style="'position: absolute; left: 100%; top: ' + (envPositions['{{ $env->uuid }}'] || 0) + 'px; z-index: 30;'"
                                        class="flex flex-col sm:flex-row items-start pl-1">
                                        <div
                                            class="relative w-56 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-neutral-200 dark:border-coolgray-200 max-h-96 overflow-y-auto scrollbar">
                                            @foreach ($envResources as $envResource)
                                                @php
                                                    $resType = $envResource['type'];
                                                    $res = $envResource['resource'];
                                                    $resRoute = match ($resType) {
                                                        'application' => route('project.application.configuration', [
                                                            'project_uuid' => $project->uuid,
                                                            'environment_uuid' => $env->uuid,
                                                            'application_uuid' => $res->uuid,
                                                        ]),
                                                        'service' => route('project.service.configuration', [
                                                            'project_uuid' => $project->uuid,
                                                            'environment_uuid' => $env->uuid,
                                                            'service_uuid' => $res->uuid,
                                                        ]),
                                                        'database' => route('project.database.configuration', [
                                                            'project_uuid' => $project->uuid,
                                                            'environment_uuid' => $env->uuid,
                                                            'database_uuid' => $res->uuid,
                                                        ]),
                                                    };
                                                @endphp
                                                <a href="{{ $resRoute }}" {{ wireNavigate() }}
                                                    class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200"
                                                    title="{{ $res->name }}">
                                                    {{ $res->name }}
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </li>
            </ol>
        </nav>
    </div>
    @if ($environment->isEmpty())
        @can('createAnyResource')
            <a href="{{ route('project.resource.create', ['project_uuid' => data_get($parameters, 'project_uuid'), 'environment_uuid' => data_get($environment, 'uuid')]) }}"
                {{ wireNavigate() }} class="items-center justify-center coolbox">+ Add Resource</a>
        @else
            <div
                class="flex flex-col items-center justify-center p-8 text-center border border-dashed border-neutral-300 dark:border-coolgray-300 rounded-lg">
                <h3 class="mb-2 text-lg font-semibold text-neutral-600 dark:text-neutral-400">No Resources Found</h3>
                <p class="text-sm text-neutral-600 dark:text-neutral-400">
                    This environment doesn't have any resources yet.<br>
                    Contact your team administrator to add resources.
                </p>
            </div>
        @endcan
    @else
        <div x-data="searchComponent()">
            <x-forms.input placeholder="Search for name, fqdn..." x-model="search" id="null" />
            <template
                x-if="filteredApplications.length === 0 && filteredDatabases.length === 0 && filteredServices.length === 0">
                <div class="flex flex-col items-center justify-center p-8 text-center">
                    <div x-show="search.length > 0">
                        <p class="text-neutral-600 dark:text-neutral-400">No resource found with the search term "<span
                                class="font-semibold" x-text="search"></span>".</p>
                        <p class="text-sm text-neutral-500 dark:text-neutral-500 mt-1">Try adjusting your search
                            criteria.</p>
                    </div>
                    <div x-show="search.length === 0">
                        <p class="text-neutral-600 dark:text-neutral-400">No resources found in this environment.</p>
                        @cannot('createAnyResource')
                            <p class="text-sm text-neutral-500 dark:text-neutral-500 mt-1">Contact your team administrator
                                to add resources.</p>
                        @endcannot
                    </div>
                </div>
            </template>

            <template x-if="filteredApplications.length > 0">
                <h2 class="pt-4">Applications</h2>
            </template>
            <div x-show="filteredApplications.length > 0"
                class="grid grid-cols-1 gap-4 pt-4 lg:grid-cols-2 xl:grid-cols-3">
                <template x-for="item in filteredApplications" :key="item.uuid">
                    <span>
                        <a class="h-24 coolbox group" :href="item.hrefLink" {{ wireNavigate() }}>
                            <div class="flex flex-col w-full">
                                <div class="flex gap-2 px-4">
                                    <div class="pb-2 truncate box-title" x-text="item.name"></div>
                                    <div class="flex-1"></div>
                                    <template x-if="item.status.startsWith('running')">
                                        <div title="running" class="bg-success badge-dashboard"></div>
                                    </template>
                                    <template x-if="item.status.startsWith('exited')">
                                        <div title="exited" class="bg-error badge-dashboard"></div>
                                    </template>
                                    <template x-if="item.status.startsWith('starting')">
                                        <div title="starting" class="bg-warning badge-dashboard"></div>
                                    </template>
                                    <template x-if="item.status.startsWith('restarting')">
                                        <div title="restarting" class="bg-warning badge-dashboard"></div>
                                    </template>
                                    <template x-if="item.status.startsWith('degraded')">
                                        <div title="degraded" class="bg-warning badge-dashboard"></div>
                                    </template>
                                </div>
                                <div class="max-w-full px-4 truncate box-description" x-text="item.description"></div>
                                <div class="max-w-full px-4 truncate box-description" x-text="item.fqdn"></div>
                                <div class="max-w-full px-4 pt-1 truncate box-description">Server: <span
                                        x-text="item.destination?.server?.name || 'Unknown'"></span></div>
                                <template x-if="item.server_status == false">
                                    <div class="px-4 text-xs font-bold text-error">Server is unreachable or
                                        misconfigured
                                    </div>
                                </template>
                            </div>
                        </a>
                        <div
                            class="flex flex-wrap gap-1 pt-1 dark:group-hover:text-white group-hover:text-black group min-h-6">
                            <template x-for="tag in item.tags">
                                <a :href="`/tags/${tag.name}`" class="tag" x-text="tag.name">
                                </a>
                            </template>
                            <a :href="`${item.hrefLink}/tags`" class="add-tag">
                                Add tag
                            </a>
                        </div>
                    </span>
                </template>
            </div>
            <template x-if="filteredDatabases.length > 0">
                <h2 class="pt-4">Databases</h2>
            </template>
            <div x-show="filteredDatabases.length > 0"
                class="grid grid-cols-1 gap-4 pt-4 lg:grid-cols-2 xl:grid-cols-3">
                <template x-for="item in filteredDatabases" :key="item.uuid">
                    <span>
                        <a class="h-24 coolbox group" :href="item.hrefLink" {{ wireNavigate() }}>
                            <div class="flex flex-col w-full">
                                <div class="flex gap-2 px-4">
                                    <div class="pb-2 truncate box-title" x-text="item.name"></div>
                                    <div class="flex-1"></div>
                                    <template x-if="item.status.startsWith('running')">
                                        <div title="running" class="bg-success badge-dashboard"></div>
                                    </template>
                                    <template x-if="item.status.startsWith('exited')">
                                        <div title="exited" class="bg-error badge-dashboard"></div>
                                    </template>
                                    <template x-if="item.status.startsWith('starting')">
                                        <div title="starting" class="bg-warning badge-dashboard"></div>
                                    </template>
                                    <template x-if="item.status.startsWith('restarting')">
                                        <div title="restarting" class="bg-warning badge-dashboard"></div>
                                    </template>
                                    <template x-if="item.status.startsWith('degraded')">
                                        <div title="degraded" class="bg-warning badge-dashboard"></div>
                                    </template>
                                </div>
                                <div class="max-w-full px-4 truncate box-description" x-text="item.description"></div>
                                <div class="max-w-full px-4 truncate box-description" x-text="item.fqdn"></div>
                                <div class="max-w-full px-4 pt-1 truncate box-description">Server: <span
                                        x-text="item.destination?.server?.name || 'Unknown'"></span></div>
                                <template x-if="item.server_status == false">
                                    <div class="px-4 text-xs font-bold text-error">Server is unreachable or
                                        misconfigured
                                    </div>
                                </template>
                            </div>
                        </a>
                        <div
                            class="flex flex-wrap gap-1 pt-1 dark:group-hover:text-white group-hover:text-black group min-h-6">
                            <template x-for="tag in item.tags">
                                <a :href="`/tags/${tag.name}`" class="tag" x-text="tag.name">
                                </a>
                            </template>
                            <a :href="`${item.hrefLink}/tags`" class="add-tag">
                                Add tag
                            </a>
                        </div>
                    </span>
                </template>
            </div>
            <template x-if="filteredServices.length > 0">
                <h2 class="pt-4">Services</h2>
            </template>
            <div x-show="filteredServices.length > 0"
                class="grid grid-cols-1 gap-4 pt-4 lg:grid-cols-2 xl:grid-cols-3">
                <template x-for="item in filteredServices" :key="item.uuid">
                    <span>
                        <a class="h-24 coolbox group" :href="item.hrefLink" {{ wireNavigate() }}>
                            <div class="flex flex-col w-full">
                                <div class="flex gap-2 px-4">
                                    <div class="pb-2 truncate box-title" x-text="item.name"></div>
                                    <div class="flex-1"></div>
                                    <template x-if="item.status.startsWith('running')">
                                        <div title="running" class="bg-success badge-dashboard"></div>
                                    </template>
                                    <template x-if="item.status.startsWith('exited')">
                                        <div title="exited" class="bg-error badge-dashboard"></div>
                                    </template>
                                    <template x-if="item.status.startsWith('starting')">
                                        <div title="starting" class="bg-warning badge-dashboard"></div>
                                    </template>
                                    <template x-if="item.status.startsWith('restarting')">
                                        <div title="restarting" class="bg-warning badge-dashboard"></div>
                                    </template>
                                    <template x-if="item.status.startsWith('degraded')">
                                        <div title="degraded" class="bg-warning badge-dashboard"></div>
                                    </template>
                                </div>
                                <div class="max-w-full px-4 truncate box-description" x-text="item.description"></div>
                                <div class="max-w-full px-4 truncate box-description" x-text="item.fqdn"></div>
                                <div class="max-w-full px-4 pt-1 truncate box-description">Server: <span
                                        x-text="item.destination?.server?.name || 'Unknown'"></span></div>
                                <template x-if="item.server_status == false">
                                    <div class="px-4 text-xs font-bold text-error">Server is unreachable or
                                        misconfigured
                                    </div>
                                </template>
                            </div>
                        </a>
                        <div
                            class="flex flex-wrap gap-1 pt-1 dark:group-hover:text-white group-hover:text-black group min-h-6">
                            <template x-for="tag in item.tags">
                                <a :href="`/tags/${tag.name}`" class="tag" x-text="tag.name">
                                </a>
                            </template>
                            <a :href="`${item.hrefLink}/tags`" class="add-tag">
                                Add tag
                            </a>
                        </div>
                    </span>
                </template>
            </div>
        </div>
    @endif

</div>

<script>
    function sortFn(a, b) {
        return a.name.localeCompare(b.name)
    }

    function searchComponent() {
        return {
            search: '',
            applications: @js($applicationsJs),
            postgresqls: @js($postgresqlsJs),
            redis: @js($redisJs),
            mongodbs: @js($mongodbsJs),
            mysqls: @js($mysqlsJs),
            mariadbs: @js($mariadbsJs),
            keydbs: @js($keydbsJs),
            dragonflies: @js($dragonfliesJs),
            clickhouses: @js($clickhousesJs),
            services: @js($servicesJs),
            filterAndSort(items) {
                if (this.search === '') {
                    return Object.values(items).sort(sortFn);
                }
                const searchLower = this.search.toLowerCase();
                return Object.values(items).filter(item => {
                    return (item.name?.toLowerCase().includes(searchLower) ||
                        item.fqdn?.toLowerCase().includes(searchLower) ||
                        item.description?.toLowerCase().includes(searchLower) ||
                        item.tags?.some(tag => tag.name.toLowerCase().includes(searchLower)));
                }).sort(sortFn);
            },
            get filteredApplications() {
                return this.filterAndSort(this.applications)
            },
            get filteredDatabases() {
                return [
                    this.postgresqls,
                    this.redis,
                    this.mongodbs,
                    this.mysqls,
                    this.mariadbs,
                    this.keydbs,
                    this.dragonflies,
                    this.clickhouses,
                ].flatMap((items) => this.filterAndSort(items))
            },
            get filteredServices() {
                return this.filterAndSort(this.services)
            }
        };
    }
</script>
