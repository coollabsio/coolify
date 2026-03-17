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
                <li class="inline-flex items-center" x-data="breadcrumbNav()">
                    <div class="flex items-center relative" @mouseenter="open()" @mouseleave="close()">
                        <a class="text-xs truncate lg:text-sm hover:text-warning" {{ wireNavigate() }}
                            href="{{ route('project.resource.index', ['project_uuid' => data_get($parameters, 'project_uuid'), 'environment_uuid' => $environment->uuid]) }}">
                            {{ $environment->name }}
                        </a>
                        <button type="button" @click.stop="toggle()" class="px-1 text-warning">
                            <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-90': envOpen }" fill="none"
                                stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="4" d="M9 5l7 7-7 7">
                                </path>
                            </svg>
                        </button>

                        <!-- Environment Dropdown Container -->
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
                                        $totalResources = $env->applications_count + $env->postgresqls_count + $env->redis_count + $env->mongodbs_count + $env->mysqls_count + $env->mariadbs_count + $env->keydbs_count + $env->dragonflies_count + $env->clickhouses_count + $env->services_count;
                                    @endphp
                                    <div @mouseenter="handleEnvHover('{{ $env->uuid }}', {{ $env->id }}, {{ $totalResources }}, $el)"
                                        @mouseleave="closeEnv()">
                                        <a href="{{ route('project.resource.index', ['project_uuid' => data_get($parameters, 'project_uuid'), 'environment_uuid' => $env->uuid]) }}"
                                            class="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-neutral-100 dark:hover:bg-coolgray-200 {{ $env->uuid === $environment->uuid ? 'dark:text-warning font-semibold' : '' }}"
                                            title="{{ $env->name }}">
                                            <span class="truncate">{{ $env->name }}</span>
                                            @if ($totalResources > 0)
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

                            <!-- Resources Sub-dropdown (Level 2) - lazy loaded via Alpine -->
                            <div x-show="activeEnv !== null && (loadingEnv === activeEnv || envResources[activeEnv])"
                                x-cloak x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                @mouseenter="openEnv(activeEnv, activeEnvId)" @mouseleave="closeEnv()"
                                :style="'position: absolute; left: 100%; top: ' + (envPositions[activeEnv] || 0) + 'px; z-index: 30;'"
                                class="flex flex-col sm:flex-row items-start pl-1">
                                <div class="relative w-48 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-neutral-200 dark:border-coolgray-200 max-h-96 overflow-y-auto scrollbar"
                                    @scroll.debounce.50ms="handleScroll($event)">
                                    <div x-show="loadingEnv === activeEnv && !envResources[activeEnv]"
                                        class="px-4 py-2 text-sm text-neutral-400">Loading...</div>
                                    <template x-for="res in (envResources[activeEnv]?.items || [])"
                                        :key="res.uuid">
                                        <div @mouseenter="openRes(activeEnv + '-' + res.uuid, res); resPositions[activeEnv + '-' + res.uuid] = $el.offsetTop - ($el.closest('.overflow-y-auto')?.scrollTop || 0)"
                                            @mouseleave="closeRes()">
                                            <a :href="resUrl(res)"
                                                class="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-neutral-100 dark:hover:bg-coolgray-200"
                                                :title="res.name + (res.serverName ? ' (' + res.serverName + ')' : '')">
                                                <span class="truncate">
                                                    <span x-text="res.name"></span>
                                                    <template x-if="res.serverName">
                                                        <span class="text-xs text-neutral-400"
                                                            x-text="'(' + res.serverName + ')'"></span>
                                                    </template>
                                                </span>
                                                <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="4" d="M9 5l7 7-7 7"></path>
                                                </svg>
                                            </a>
                                        </div>
                                    </template>
                                    <div x-show="loadingMore"
                                        class="px-4 py-2 text-xs text-center text-neutral-400">Loading more...
                                    </div>
                                </div>

                                <!-- Menu Sub-dropdown (Level 3) -->
                                <div x-show="activeRes && hoveredRes" x-cloak
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                    @mouseenter="openRes(activeRes, hoveredRes)" @mouseleave="closeRes()"
                                    :style="'position: absolute; left: 100%; top: ' + (resPositions[activeRes] || 0) + 'px; z-index: 40;'"
                                    class="flex flex-col sm:flex-row items-start pl-1">
                                    <div
                                        class="relative w-48 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-neutral-200 dark:border-coolgray-200">
                                        <div @mouseenter="openMenu(activeRes + '-config'); menuPositions[activeRes + '-config'] = $el.offsetTop"
                                            @mouseleave="closeMenu()">
                                            <a :href="resUrl(hoveredRes)"
                                                class="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-neutral-100 dark:hover:bg-coolgray-200">
                                                <span>Configuration</span>
                                                <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="4" d="M9 5l7 7-7 7"></path>
                                                </svg>
                                            </a>
                                        </div>
                                        <template x-if="hoveredRes?.type === 'application'">
                                            <a :href="resUrl(hoveredRes, 'deployment')"
                                                class="block px-4 py-2 text-sm hover:bg-neutral-100 dark:hover:bg-coolgray-200">Deployments</a>
                                        </template>
                                        <a :href="resUrl(hoveredRes, 'logs')"
                                            class="block px-4 py-2 text-sm hover:bg-neutral-100 dark:hover:bg-coolgray-200">Logs</a>
                                        <template x-if="canAccessTerminal">
                                            <a :href="resUrl(hoveredRes, 'terminal')"
                                                class="block px-4 py-2 text-sm hover:bg-neutral-100 dark:hover:bg-coolgray-200">Terminal</a>
                                        </template>
                                        <template
                                            x-if="hoveredRes?.type === 'database' && hoveredRes?.canBackup">
                                            <a :href="resUrl(hoveredRes, 'backups')"
                                                class="block px-4 py-2 text-sm hover:bg-neutral-100 dark:hover:bg-coolgray-200">Backups</a>
                                        </template>
                                    </div>

                                    <!-- Configuration Sub-menu (Level 4) -->
                                    <template x-if="activeMenuEnv === activeRes + '-config'">
                                        <div @mouseenter="openMenu(activeRes + '-config')"
                                            @mouseleave="closeMenu()"
                                            :style="'position: absolute; left: 100%; top: ' + (menuPositions[activeRes + '-config'] || 0) + 'px; z-index: 50;'"
                                            class="pl-1">
                                            <div
                                                class="w-52 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-neutral-200 dark:border-coolgray-200 max-h-96 overflow-y-auto scrollbar">
                                                <template x-if="hoveredRes?.type === 'application'">
                                                    <div>
                                                        <a :href="resUrl(hoveredRes)"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">General</a>
                                                        <a :href="resUrl(hoveredRes, 'environment-variables')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Environment
                                                            Variables</a>
                                                        <a :href="resUrl(hoveredRes, 'persistent-storage')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Persistent
                                                            Storage</a>
                                                        <a :href="resUrl(hoveredRes, 'source')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Source</a>
                                                        <a :href="resUrl(hoveredRes, 'servers')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Servers</a>
                                                        <a :href="resUrl(hoveredRes, 'scheduled-tasks')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Scheduled
                                                            Tasks</a>
                                                        <a :href="resUrl(hoveredRes, 'webhooks')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Webhooks</a>
                                                        <a :href="resUrl(hoveredRes, 'preview-deployments')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Preview
                                                            Deployments</a>
                                                        <a :href="resUrl(hoveredRes, 'healthcheck')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Healthcheck</a>
                                                        <a :href="resUrl(hoveredRes, 'rollback')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Rollback</a>
                                                        <a :href="resUrl(hoveredRes, 'resource-limits')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Resource
                                                            Limits</a>
                                                        <a :href="resUrl(hoveredRes, 'resource-operations')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Resource
                                                            Operations</a>
                                                        <a :href="resUrl(hoveredRes, 'metrics')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Metrics</a>
                                                        <a :href="resUrl(hoveredRes, 'tags')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Tags</a>
                                                        <a :href="resUrl(hoveredRes, 'advanced')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Advanced</a>
                                                        <a :href="resUrl(hoveredRes, 'danger')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200 text-red-500">Danger
                                                            Zone</a>
                                                    </div>
                                                </template>
                                                <template x-if="hoveredRes?.type === 'service'">
                                                    <div>
                                                        <a :href="resUrl(hoveredRes)"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">General</a>
                                                        <a :href="resUrl(hoveredRes, 'environment-variables')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Environment
                                                            Variables</a>
                                                        <a :href="resUrl(hoveredRes, 'storages')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Storages</a>
                                                        <a :href="resUrl(hoveredRes, 'scheduled-tasks')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Scheduled
                                                            Tasks</a>
                                                        <a :href="resUrl(hoveredRes, 'webhooks')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Webhooks</a>
                                                        <a :href="resUrl(hoveredRes, 'resource-operations')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Resource
                                                            Operations</a>
                                                        <a :href="resUrl(hoveredRes, 'tags')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Tags</a>
                                                        <a :href="resUrl(hoveredRes, 'danger')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200 text-red-500">Danger
                                                            Zone</a>
                                                    </div>
                                                </template>
                                                <template x-if="hoveredRes?.type === 'database'">
                                                    <div>
                                                        <a :href="resUrl(hoveredRes)"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">General</a>
                                                        <a :href="resUrl(hoveredRes, 'environment-variables')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Environment
                                                            Variables</a>
                                                        <a :href="resUrl(hoveredRes, 'servers')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Servers</a>
                                                        <a :href="resUrl(hoveredRes, 'persistent-storage')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Persistent
                                                            Storage</a>
                                                        <a :href="resUrl(hoveredRes, 'webhooks')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Webhooks</a>
                                                        <a :href="resUrl(hoveredRes, 'resource-limits')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Resource
                                                            Limits</a>
                                                        <a :href="resUrl(hoveredRes, 'resource-operations')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Resource
                                                            Operations</a>
                                                        <a :href="resUrl(hoveredRes, 'metrics')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Metrics</a>
                                                        <a :href="resUrl(hoveredRes, 'tags')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200">Tags</a>
                                                        <a :href="resUrl(hoveredRes, 'danger')"
                                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200 text-red-500">Danger
                                                            Zone</a>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
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

    function breadcrumbNav() {
        return {
            envOpen: false,
            activeEnv: null,
            activeEnvId: null,
            activeRes: null,
            activeMenuEnv: null,
            hoveredRes: null,
            envPositions: {},
            resPositions: {},
            menuPositions: {},
            envResources: {},
            loadingEnv: null,
            loadingMore: false,
            closeTimeout: null,
            envTimeout: null,
            resTimeout: null,
            menuTimeout: null,
            projectUuid: @js($project->uuid),
            canAccessTerminal: @js($canAccessTerminal),
            toggle() {
                this.envOpen = !this.envOpen;
                if (!this.envOpen) {
                    this.activeEnv = null;
                    this.activeEnvId = null;
                    this.activeRes = null;
                    this.activeMenuEnv = null;
                    this.hoveredRes = null;
                }
            },
            open() {
                clearTimeout(this.closeTimeout);
                this.envOpen = true;
            },
            close() {
                this.closeTimeout = setTimeout(() => {
                    this.envOpen = false;
                    this.activeEnv = null;
                    this.activeEnvId = null;
                    this.activeRes = null;
                    this.activeMenuEnv = null;
                    this.hoveredRes = null;
                }, 100);
            },
            openEnv(uuid, id) {
                clearTimeout(this.closeTimeout);
                clearTimeout(this.envTimeout);
                this.activeEnv = uuid;
                this.activeEnvId = id;
            },
            closeEnv() {
                this.envTimeout = setTimeout(() => {
                    this.activeEnv = null;
                    this.activeEnvId = null;
                    this.activeRes = null;
                    this.activeMenuEnv = null;
                    this.hoveredRes = null;
                }, 100);
            },
            openRes(key, res) {
                clearTimeout(this.envTimeout);
                clearTimeout(this.resTimeout);
                this.activeMenuEnv = null;
                this.activeRes = key;
                this.hoveredRes = res;
            },
            closeRes() {
                this.resTimeout = setTimeout(() => {
                    this.activeRes = null;
                    this.activeMenuEnv = null;
                    this.hoveredRes = null;
                }, 100);
            },
            openMenu(key) {
                clearTimeout(this.resTimeout);
                clearTimeout(this.menuTimeout);
                this.activeMenuEnv = key;
            },
            closeMenu() {
                this.menuTimeout = setTimeout(() => {
                    this.activeMenuEnv = null;
                }, 100);
            },
            handleEnvHover(uuid, id, total, el) {
                clearTimeout(this.closeTimeout);
                clearTimeout(this.envTimeout);
                this.envPositions[uuid] = el.offsetTop - (el.closest('.overflow-y-auto')?.scrollTop || 0);
                this.activeRes = null;
                this.activeMenuEnv = null;
                this.hoveredRes = null;
                if (total === 0) {
                    this.activeEnv = null;
                    this.activeEnvId = null;
                    return;
                }
                this.activeEnv = uuid;
                this.activeEnvId = id;
                this.loadResources(id, uuid);
            },
            resUrl(res, suffix) {
                if (!res) return '#';
                const base = `/project/${this.projectUuid}/environment/${this.activeEnv}/${res.type}/${res.uuid}`;
                return suffix ? `${base}/${suffix}` : base;
            },
            async loadResources(envId, envUuid) {
                if (this.envResources[envUuid]) return;
                if (this.loadingEnv === envUuid) return;
                this.loadingEnv = envUuid;
                try {
                    const result = await this.$wire.loadBreadcrumbResources(envId, 1);
                    this.envResources[envUuid] = {
                        items: result.resources,
                        hasMore: result.hasMore,
                        page: 1,
                    };
                } finally {
                    if (this.loadingEnv === envUuid) {
                        this.loadingEnv = null;
                    }
                }
            },
            async loadMoreResources() {
                const cache = this.envResources[this.activeEnv];
                if (!cache || !cache.hasMore || this.loadingMore) return;
                this.loadingMore = true;
                try {
                    const result = await this.$wire.loadBreadcrumbResources(this.activeEnvId, cache.page + 1);
                    cache.items = [...cache.items, ...result.resources];
                    cache.hasMore = result.hasMore;
                    cache.page++;
                } finally {
                    this.loadingMore = false;
                }
            },
            handleScroll(event) {
                const el = event.target;
                if (el.scrollTop + el.clientHeight >= el.scrollHeight - 20) {
                    this.loadMoreResources();
                }
            },
        };
    }

    function searchComponent() {
        return {
            search: '',
            applications: @js($applications),
            postgresqls: @js($postgresqls),
            redis: @js($redis),
            mongodbs: @js($mongodbs),
            mysqls: @js($mysqls),
            mariadbs: @js($mariadbs),
            keydbs: @js($keydbs),
            dragonflies: @js($dragonflies),
            clickhouses: @js($clickhouses),
            services: @js($services),
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
