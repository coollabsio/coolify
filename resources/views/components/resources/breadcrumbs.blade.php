@props([
    'lastDeploymentInfo' => null,
    'lastDeploymentLink' => null,
    'resource' => null,
    'projects' => null,
    'environments' => null,
])
@php
    use App\Models\Project;

    // Use passed props if available, otherwise query (backwards compatible)
    $projects = $projects ?? Project::ownedByCurrentTeamCached();
    $environments = $environments ?? $resource->environment->project
        ->environments()
        ->select('id', 'uuid', 'name', 'project_id')
        ->with([
            'applications:id,uuid,name,environment_id',
            'services:id,uuid,name,environment_id',
            'postgresqls:id,uuid,name,environment_id',
            'redis:id,uuid,name,environment_id',
            'mongodbs:id,uuid,name,environment_id',
            'mysqls:id,uuid,name,environment_id',
            'mariadbs:id,uuid,name,environment_id',
            'keydbs:id,uuid,name,environment_id',
            'dragonflies:id,uuid,name,environment_id',
            'clickhouses:id,uuid,name,environment_id',
        ])
        ->get();
    $currentProjectUuid = data_get($resource, 'environment.project.uuid');
    $currentEnvironmentUuid = data_get($resource, 'environment.uuid');
    $currentResourceUuid = data_get($resource, 'uuid');
@endphp
<nav class="flex pt-2 pb-10">
    <ol class="flex flex-wrap items-center gap-y-1">
        <!-- Project Level -->
        <li class="inline-flex items-center" x-data="{ projectOpen: false, closeTimeout: null, toggle() { this.projectOpen = !this.projectOpen }, open() { clearTimeout(this.closeTimeout); this.projectOpen = true }, close() { this.closeTimeout = setTimeout(() => { this.projectOpen = false }, 100) } }">
            <div class="flex items-center relative" @mouseenter="open()" @mouseleave="close()">
                <a class="text-xs truncate lg:text-sm hover:text-warning" {{ wireNavigate() }}
                    href="{{ route('project.show', ['project_uuid' => $currentProjectUuid]) }}">
                    {{ data_get($resource, 'environment.project.name', 'Undefined Name') }}
                </a>
                <button type="button" @click.stop="toggle()" class="px-1 text-warning">
                    <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-down': projectOpen }" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="4" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>

                <!-- Project Dropdown -->
                <div x-show="projectOpen" @click.outside="close()" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                    class="absolute z-20 top-full mt-1 w-56 -ml-2 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-neutral-200 dark:border-coolgray-200 max-h-96 overflow-y-auto scrollbar">
                    @foreach ($projects as $project)
                        <a href="{{ route('project.show', ['project_uuid' => $project->uuid]) }}" {{ wireNavigate() }}
                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200 {{ $project->uuid === $currentProjectUuid ? 'dark:text-warning font-semibold' : '' }}"
                            title="{{ $project->name }}">
                            {{ $project->name }}
                        </a>
                    @endforeach
                </div>
            </div>
        </li>

        <!-- Environment Level -->
        <li class="inline-flex items-center" x-data="{ envOpen: false, activeEnv: null, envPositions: {}, closeTimeout: null, envTimeout: null, toggle() { this.envOpen = !this.envOpen; if (!this.envOpen) { this.activeEnv = null; } }, open() { clearTimeout(this.closeTimeout); this.envOpen = true }, close() { this.closeTimeout = setTimeout(() => { this.envOpen = false; this.activeEnv = null; }, 100) }, openEnv(id) { clearTimeout(this.closeTimeout); clearTimeout(this.envTimeout); this.activeEnv = id }, closeEnv() { this.envTimeout = setTimeout(() => { this.activeEnv = null; }, 100) } }">
            <div class="flex items-center relative" @mouseenter="open()"
                @mouseleave="close()">
                <a class="text-xs truncate lg:text-sm hover:text-warning" {{ wireNavigate() }}
                    href="{{ route('project.resource.index', [
                        'environment_uuid' => $currentEnvironmentUuid,
                        'project_uuid' => $currentProjectUuid,
                    ]) }}">
                    {{ data_get($resource, 'environment.name') }}
                </a>
                <button type="button" @click.stop="toggle()" class="px-1 text-warning">
                    <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-down': envOpen }" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="4" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>

                <!-- Environment Dropdown -->
                <div x-show="envOpen" @click.outside="close()" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                    class="absolute z-20 top-full mt-1 left-0 sm:left-auto max-w-[calc(100vw-1rem)]"
                    x-init="$nextTick(() => { const rect = $el.getBoundingClientRect(); if (rect.right > window.innerWidth) { $el.style.left = 'auto'; $el.style.right = '0'; } })">
                    <!-- Environment List -->
                    <div
                        class="relative w-48 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-neutral-200 dark:border-coolgray-200 max-h-96 overflow-y-auto scrollbar">
                        @foreach ($environments as $environment)
                            @php
                                $envDatabases = collect()
                                    ->merge($environment->postgresqls ?? collect())
                                    ->merge($environment->redis ?? collect())
                                    ->merge($environment->mongodbs ?? collect())
                                    ->merge($environment->mysqls ?? collect())
                                    ->merge($environment->mariadbs ?? collect())
                                    ->merge($environment->keydbs ?? collect())
                                    ->merge($environment->dragonflies ?? collect())
                                    ->merge($environment->clickhouses ?? collect());
                                $envResources = collect()
                                    ->merge($environment->applications->map(fn($app) => ['type' => 'application', 'resource' => $app]))
                                    ->merge($envDatabases->map(fn($db) => ['type' => 'database', 'resource' => $db]))
                                    ->merge($environment->services->map(fn($svc) => ['type' => 'service', 'resource' => $svc]))
                                    ->sortBy(fn($item) => strtolower($item['resource']->name));
                            @endphp
                            <div @mouseenter="openEnv('{{ $environment->uuid }}'); envPositions['{{ $environment->uuid }}'] = $el.offsetTop - ($el.closest('.overflow-y-auto')?.scrollTop || 0)"
                                @mouseleave="closeEnv()">
                                <a href="{{ route('project.resource.index', [
                                        'environment_uuid' => $environment->uuid,
                                        'project_uuid' => $currentProjectUuid,
                                    ]) }}" {{ wireNavigate() }}
                                    class="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-neutral-100 dark:hover:bg-coolgray-200 {{ $environment->uuid === $currentEnvironmentUuid ? 'dark:text-warning font-semibold' : '' }}"
                                    title="{{ $environment->name }}">
                                    <span class="truncate">{{ $environment->name }}</span>
                                    @if ($envResources->count() > 0)
                                        <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="4"
                                                d="M9 5l7 7-7 7"></path>
                                        </svg>
                                    @endif
                                </a>
                            </div>
                        @endforeach
                        <div class="border-t border-neutral-200 dark:border-coolgray-200 mt-1 pt-1">
                            <a href="{{ route('project.show', ['project_uuid' => $currentProjectUuid]) }}" {{ wireNavigate() }}
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
                    @foreach ($environments as $environment)
                        @php
                            $envDatabases = collect()
                                ->merge($environment->postgresqls ?? collect())
                                ->merge($environment->redis ?? collect())
                                ->merge($environment->mongodbs ?? collect())
                                ->merge($environment->mysqls ?? collect())
                                ->merge($environment->mariadbs ?? collect())
                                ->merge($environment->keydbs ?? collect())
                                ->merge($environment->dragonflies ?? collect())
                                ->merge($environment->clickhouses ?? collect());
                            $envResources = collect()
                                ->merge($environment->applications->map(fn($app) => ['type' => 'application', 'resource' => $app]))
                                ->merge($envDatabases->map(fn($db) => ['type' => 'database', 'resource' => $db]))
                                ->merge($environment->services->map(fn($svc) => ['type' => 'service', 'resource' => $svc]));
                        @endphp
                        @if ($envResources->count() > 0)
                            <div x-show="activeEnv === '{{ $environment->uuid }}'" x-cloak
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                @mouseenter="openEnv('{{ $environment->uuid }}')" @mouseleave="closeEnv()"
                                :style="'position: absolute; left: 100%; top: ' + (envPositions['{{ $environment->uuid }}'] || 0) + 'px; z-index: 30;'"
                                class="flex flex-col sm:flex-row items-start pl-1">
                                <div
                                    class="relative w-56 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-neutral-200 dark:border-coolgray-200 max-h-96 overflow-y-auto scrollbar">
                                    @foreach ($envResources as $envResource)
                                        @php
                                            $resType = $envResource['type'];
                                            $res = $envResource['resource'];
                                            $resRoute = match ($resType) {
                                                'application' => route('project.application.configuration', [
                                                    'project_uuid' => $currentProjectUuid,
                                                    'environment_uuid' => $environment->uuid,
                                                    'application_uuid' => $res->uuid,
                                                ]),
                                                'service' => route('project.service.configuration', [
                                                    'project_uuid' => $currentProjectUuid,
                                                    'environment_uuid' => $environment->uuid,
                                                    'service_uuid' => $res->uuid,
                                                ]),
                                                'database' => route('project.database.configuration', [
                                                    'project_uuid' => $currentProjectUuid,
                                                    'environment_uuid' => $environment->uuid,
                                                    'database_uuid' => $res->uuid,
                                                ]),
                                            };
                                            $isCurrentResource = $res->uuid === $currentResourceUuid;
                                        @endphp
                                        <a href="{{ $resRoute }}" {{ wireNavigate() }}
                                            class="block px-4 py-2 text-sm truncate hover:bg-neutral-100 dark:hover:bg-coolgray-200 {{ $isCurrentResource ? 'dark:text-warning font-semibold' : '' }}"
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

        <!-- Resource Level -->
        @php
            $resourceUuid = data_get($resource, 'uuid');
            $resourceType = $resource->getMorphClass();
            $isApplication = $resourceType === 'App\Models\Application';
            $isService = $resourceType === 'App\Models\Service';
            $isDatabase = str_contains($resourceType, 'Database') || str_contains($resourceType, 'Standalone');
            $hasMultipleServers = $isApplication && method_exists($resource, 'additional_servers') &&
                ($resource->relationLoaded('additional_servers') ? $resource->additional_servers->count() > 0 : ($resource->additional_servers_count ?? 0) > 0);
            $serverName = $hasMultipleServers ? null : data_get($resource, 'destination.server.name');
            $routeParams = [
                'project_uuid' => $currentProjectUuid,
                'environment_uuid' => $currentEnvironmentUuid,
            ];
            if ($isApplication) {
                $routeParams['application_uuid'] = $resourceUuid;
            } elseif ($isService) {
                $routeParams['service_uuid'] = $resourceUuid;
            } else {
                $routeParams['database_uuid'] = $resourceUuid;
            }
        @endphp
        <li class="inline-flex items-center mr-2">
            <a class="text-xs truncate lg:text-sm hover:text-warning" {{ wireNavigate() }}
                href="{{ $isApplication
                    ? route('project.application.configuration', $routeParams)
                    : ($isService
                        ? route('project.service.configuration', $routeParams)
                        : route('project.database.configuration', $routeParams)) }}"
                title="{{ data_get($resource, 'name') }}{{ $serverName ? ' ('.$serverName.')' : '' }}">
                {{ data_get($resource, 'name') }}@if($serverName) <span class="text-xs text-neutral-400">({{ $serverName }})</span>@endif
            </a>
        </li>

        <!-- Current Section Status -->
        @if ($resource->getMorphClass() == 'App\Models\Service')
            <x-status.services :service="$resource" />
        @else
            <x-status.index :resource="$resource" :title="$lastDeploymentInfo" :lastDeploymentLink="$lastDeploymentLink" />
        @endif
    </ol>
</nav>

<style>
    .rotate-down {
        transform: rotate(90deg);
    }

    .transition-transform {
        transition: transform 0.2s ease;
    }
</style>
