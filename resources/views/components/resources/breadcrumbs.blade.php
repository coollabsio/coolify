@props([
    'lastDeploymentInfo' => null,
    'lastDeploymentLink' => null,
    'resource' => null,
])
@php
    $projects = auth()->user()->currentTeam()->projects()->get();
    $environments = $resource->environment->project
        ->environments()
        ->with(['applications', 'services'])
        ->get();
    $currentProjectUuid = data_get($resource, 'environment.project.uuid');
    $currentEnvironmentUuid = data_get($resource, 'environment.uuid');
    $currentResourceUuid = data_get($resource, 'uuid');
@endphp
<nav class="flex pt-2 pb-10">
    <ol class="flex flex-wrap items-center gap-y-1">
        <!-- Project Level -->
        <li class="inline-flex items-center" x-data="{ projectOpen: false }">
            <div class="flex items-center relative" @mouseenter="projectOpen = true" @mouseleave="projectOpen = false">
                <a class="text-xs truncate lg:text-sm hover:text-warning"
                    href="{{ route('project.show', ['project_uuid' => $currentProjectUuid]) }}">
                    {{ data_get($resource, 'environment.project.name', 'Undefined Name') }}
                </a>
                <span class="px-1 text-warning">
                    <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-down': projectOpen }" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="4" d="M9 5l7 7-7 7"></path>
                    </svg>
                </span>

                <!-- Project Dropdown -->
                <div x-show="projectOpen" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                    class="absolute z-20 top-full mt-1 w-56 -ml-2 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-coolgray-200 max-h-96 overflow-y-auto scrollbar">
                    @foreach ($projects as $project)
                        <a href="{{ route('project.show', ['project_uuid' => $project->uuid]) }}"
                            class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200 {{ $project->uuid === $currentProjectUuid ? 'dark:text-warning font-semibold' : '' }}"
                            title="{{ $project->name }}">
                            {{ $project->name }}
                        </a>
                    @endforeach
                </div>
            </div>
        </li>

        <!-- Environment Level -->
        <li class="inline-flex items-center" x-data="{ envOpen: false, activeEnv: null, activeRes: null, activeMenuEnv: null }">
            <div class="flex items-center relative" @mouseenter="envOpen = true"
                @mouseleave="envOpen = false; activeEnv = null; activeRes = null; activeMenuEnv = null">
                <a class="text-xs truncate lg:text-sm hover:text-warning"
                    href="{{ route('project.resource.index', [
                        'environment_uuid' => $currentEnvironmentUuid,
                        'project_uuid' => $currentProjectUuid,
                    ]) }}">
                    {{ data_get($resource, 'environment.name') }}
                </a>
                <span class="px-1 text-warning">
                    <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-down': envOpen }" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="4" d="M9 5l7 7-7 7"></path>
                    </svg>
                </span>

                <!-- Environment Dropdown Container -->
                <div x-show="envOpen" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95" class="absolute z-20 top-full mt-1 flex items-start">
                    <!-- Environment List -->
                    <div
                        class="w-48 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-coolgray-200 max-h-96 overflow-y-auto scrollbar">
                        @foreach ($environments as $environment)
                            @php
                                $envResources = collect()
                                    ->merge(
                                        $environment->applications->map(
                                            fn($app) => ['type' => 'application', 'resource' => $app],
                                        ),
                                    )
                                    ->merge(
                                        $environment
                                            ->databases()
                                            ->map(fn($db) => ['type' => 'database', 'resource' => $db]),
                                    )
                                    ->merge(
                                        $environment->services->map(
                                            fn($svc) => ['type' => 'service', 'resource' => $svc],
                                        ),
                                    );
                            @endphp
                            <div @mouseenter="activeEnv = '{{ $environment->uuid }}'; activeRes = null"
                                @mouseleave="activeEnv = null">
                                <a href="{{ route('project.resource.index', [
                                    'environment_uuid' => $environment->uuid,
                                    'project_uuid' => $currentProjectUuid,
                                ]) }}"
                                    class="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200 {{ $environment->uuid === $currentEnvironmentUuid ? 'dark:text-warning font-semibold' : '' }}"
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
                        <div class="border-t border-coolgray-200 mt-1 pt-1">
                            <a href="{{ route('project.show', ['project_uuid' => $currentProjectUuid]) }}"
                                class="flex items-center gap-2 px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
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
                            $envResources = collect()
                                ->merge(
                                    $environment->applications->map(
                                        fn($app) => ['type' => 'application', 'resource' => $app],
                                    ),
                                )
                                ->merge(
                                    $environment
                                        ->databases()
                                        ->map(fn($db) => ['type' => 'database', 'resource' => $db]),
                                )
                                ->merge(
                                    $environment->services->map(fn($svc) => ['type' => 'service', 'resource' => $svc]),
                                );
                        @endphp
                        @if ($envResources->count() > 0)
                            <div x-show="activeEnv === '{{ $environment->uuid }}'"
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                x-transition:leave="transition ease-in duration-100"
                                x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                                @mouseenter="activeEnv = '{{ $environment->uuid }}'"
                                @mouseleave="activeEnv = null; activeRes = null" class="ml-1 flex items-start">
                                <div
                                    class="w-48 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-coolgray-200 max-h-96 overflow-y-auto scrollbar">
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
                                        <div @mouseenter="activeRes = '{{ $environment->uuid }}-{{ $res->uuid }}'"
                                            @mouseleave="activeRes = null">
                                            <a href="{{ $resRoute }}"
                                                class="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200 {{ $isCurrentResource ? 'dark:text-warning font-semibold' : '' }}"
                                                title="{{ $res->name }}">
                                                <span class="truncate">{{ $res->name }}</span>
                                                <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="4" d="M9 5l7 7-7 7"></path>
                                                </svg>
                                            </a>
                                        </div>
                                    @endforeach
                                </div>

                                <!-- Main Menu Sub-dropdown (3rd level) -->
                                @foreach ($envResources as $envResource)
                                    @php
                                        $resType = $envResource['type'];
                                        $res = $envResource['resource'];
                                        $resParams = [
                                            'project_uuid' => $currentProjectUuid,
                                            'environment_uuid' => $environment->uuid,
                                        ];
                                        if ($resType === 'application') {
                                            $resParams['application_uuid'] = $res->uuid;
                                        } elseif ($resType === 'service') {
                                            $resParams['service_uuid'] = $res->uuid;
                                        } else {
                                            $resParams['database_uuid'] = $res->uuid;
                                        }
                                        $resKey = $environment->uuid . '-' . $res->uuid;
                                    @endphp
                                    <div x-show="activeRes === '{{ $resKey }}'"
                                        x-transition:enter="transition ease-out duration-150"
                                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                        x-transition:leave="transition ease-in duration-100"
                                        x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                                        @mouseenter="activeRes = '{{ $resKey }}'"
                                        @mouseleave="activeRes = null; activeMenuEnv = null"
                                        class="ml-1 flex items-start">
                                        <!-- Main Menu List -->
                                        <div
                                            class="w-48 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-coolgray-200">
                                            @if ($resType === 'application')
                                                <div @mouseenter="activeMenuEnv = '{{ $resKey }}-config'"
                                                    @mouseleave="activeMenuEnv = null">
                                                    <a href="{{ route('project.application.configuration', $resParams) }}"
                                                        class="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                                                        <span>Configuration</span>
                                                        <svg class="w-3 h-3 shrink-0" fill="none"
                                                            stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="4" d="M9 5l7 7-7 7"></path>
                                                        </svg>
                                                    </a>
                                                </div>
                                                <a href="{{ route('project.application.deployment.index', $resParams) }}"
                                                    class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Deployments</a>
                                                <a href="{{ route('project.application.logs', $resParams) }}"
                                                    class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Logs</a>
                                                @can('canAccessTerminal')
                                                    <a href="{{ route('project.application.command', $resParams) }}"
                                                        class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Terminal</a>
                                                @endcan
                                            @elseif ($resType === 'service')
                                                <div @mouseenter="activeMenuEnv = '{{ $resKey }}-config'"
                                                    @mouseleave="activeMenuEnv = null">
                                                    <a href="{{ route('project.service.configuration', $resParams) }}"
                                                        class="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                                                        <span>Configuration</span>
                                                        <svg class="w-3 h-3 shrink-0" fill="none"
                                                            stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="4" d="M9 5l7 7-7 7"></path>
                                                        </svg>
                                                    </a>
                                                </div>
                                                <a href="{{ route('project.service.logs', $resParams) }}"
                                                    class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Logs</a>
                                                @can('canAccessTerminal')
                                                    <a href="{{ route('project.service.command', $resParams) }}"
                                                        class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Terminal</a>
                                                @endcan
                                            @else
                                                <div @mouseenter="activeMenuEnv = '{{ $resKey }}-config'"
                                                    @mouseleave="activeMenuEnv = null">
                                                    <a href="{{ route('project.database.configuration', $resParams) }}"
                                                        class="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                                                        <span>Configuration</span>
                                                        <svg class="w-3 h-3 shrink-0" fill="none"
                                                            stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2" d="M9 5l7 7-7 7"></path>
                                                        </svg>
                                                    </a>
                                                </div>
                                                <a href="{{ route('project.database.logs', $resParams) }}"
                                                    class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Logs</a>
                                                @can('canAccessTerminal')
                                                    <a href="{{ route('project.database.command', $resParams) }}"
                                                        class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Terminal</a>
                                                @endcan
                                                @if (
                                                    $res->getMorphClass() === 'App\Models\StandalonePostgresql' ||
                                                        $res->getMorphClass() === 'App\Models\StandaloneMongodb' ||
                                                        $res->getMorphClass() === 'App\Models\StandaloneMysql' ||
                                                        $res->getMorphClass() === 'App\Models\StandaloneMariadb')
                                                    <a href="{{ route('project.database.backup.index', $resParams) }}"
                                                        class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Backups</a>
                                                @endif
                                            @endif
                                        </div>

                                        <!-- Configuration Sub-menu (4th level) -->
                                        <div x-show="activeMenuEnv === '{{ $resKey }}-config'"
                                            x-transition:enter="transition ease-out duration-150"
                                            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                            x-transition:leave="transition ease-in duration-100"
                                            x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                                            @mouseenter="activeMenuEnv = '{{ $resKey }}-config'"
                                            @mouseleave="activeMenuEnv = null"
                                            class="ml-1 w-52 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-coolgray-200 max-h-96 overflow-y-auto scrollbar">
                                            @if ($resType === 'application')
                                                <a href="{{ route('project.application.configuration', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">General</a>
                                                <a href="{{ route('project.application.environment-variables', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Environment
                                                    Variables</a>
                                                <a href="{{ route('project.application.persistent-storage', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Persistent
                                                    Storage</a>
                                                <a href="{{ route('project.application.source', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Source</a>
                                                <a href="{{ route('project.application.servers', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Servers</a>
                                                <a href="{{ route('project.application.scheduled-tasks.show', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Scheduled
                                                    Tasks</a>
                                                <a href="{{ route('project.application.webhooks', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Webhooks</a>
                                                <a href="{{ route('project.application.preview-deployments', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Preview
                                                    Deployments</a>
                                                <a href="{{ route('project.application.healthcheck', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Healthcheck</a>
                                                <a href="{{ route('project.application.rollback', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Rollback</a>
                                                <a href="{{ route('project.application.resource-limits', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Resource
                                                    Limits</a>
                                                <a href="{{ route('project.application.resource-operations', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Resource
                                                    Operations</a>
                                                <a href="{{ route('project.application.metrics', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Metrics</a>
                                                <a href="{{ route('project.application.tags', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Tags</a>
                                                <a href="{{ route('project.application.advanced', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Advanced</a>
                                                <a href="{{ route('project.application.danger', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200 text-red-500">Danger
                                                    Zone</a>
                                            @elseif ($resType === 'service')
                                                <a href="{{ route('project.service.configuration', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">General</a>
                                                <a href="{{ route('project.service.environment-variables', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Environment
                                                    Variables</a>
                                                <a href="{{ route('project.service.storages', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Storages</a>
                                                <a href="{{ route('project.service.scheduled-tasks.show', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Scheduled
                                                    Tasks</a>
                                                <a href="{{ route('project.service.webhooks', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Webhooks</a>
                                                <a href="{{ route('project.service.resource-operations', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Resource
                                                    Operations</a>
                                                <a href="{{ route('project.service.tags', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Tags</a>
                                                <a href="{{ route('project.service.danger', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200 text-red-500">Danger
                                                    Zone</a>
                                            @else
                                                <a href="{{ route('project.database.configuration', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">General</a>
                                                <a href="{{ route('project.database.environment-variables', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Environment
                                                    Variables</a>
                                                <a href="{{ route('project.database.servers', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Servers</a>
                                                <a href="{{ route('project.database.persistent-storage', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Persistent
                                                    Storage</a>
                                                <a href="{{ route('project.database.webhooks', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Webhooks</a>
                                                <a href="{{ route('project.database.resource-limits', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Resource
                                                    Limits</a>
                                                <a href="{{ route('project.database.resource-operations', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Resource
                                                    Operations</a>
                                                <a href="{{ route('project.database.metrics', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Metrics</a>
                                                <a href="{{ route('project.database.tags', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Tags</a>
                                                <a href="{{ route('project.database.danger', $resParams) }}"
                                                    class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200 text-red-500">Danger
                                                    Zone</a>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
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
        <li class="inline-flex items-center" x-data="{ resourceOpen: false, activeMenu: null }">
            <div class="flex items-center relative" @mouseenter="resourceOpen = true"
                @mouseleave="resourceOpen = false; activeMenu = null">
                <a class="text-xs truncate lg:text-sm hover:text-warning"
                    href="{{ $isApplication
                        ? route('project.application.configuration', $routeParams)
                        : ($isService
                            ? route('project.service.configuration', $routeParams)
                            : route('project.database.configuration', $routeParams)) }}">
                    {{ data_get($resource, 'name') }}
                </a>
                <span class="px-1 text-warning">
                    <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-down': resourceOpen }" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="4" d="M9 5l7 7-7 7">
                        </path>
                    </svg>
                </span>

                <!-- Resource Dropdown Container -->
                <div x-show="resourceOpen" x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75"
                    x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                    class="absolute z-20 top-full mt-1 flex items-start">
                    <!-- Main Menu List -->
                    <div
                        class="w-48 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-coolgray-200">
                        @if ($isApplication)
                            <!-- Application Main Menus -->
                            <div @mouseenter="activeMenu = 'config'" @mouseleave="activeMenu = null">
                                <a href="{{ route('project.application.configuration', $routeParams) }}"
                                    class="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                                    <span>Configuration</span>
                                    <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="4"
                                            d="M9 5l7 7-7 7"></path>
                                    </svg>
                                </a>
                            </div>
                            <a href="{{ route('project.application.deployment.index', $routeParams) }}"
                                class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                                Deployments
                            </a>
                            <a href="{{ route('project.application.logs', $routeParams) }}"
                                class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                                Logs
                            </a>
                            @can('canAccessTerminal')
                                <a href="{{ route('project.application.command', $routeParams) }}"
                                    class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                                    Terminal
                                </a>
                            @endcan
                        @elseif ($isService)
                            <!-- Service Main Menus -->
                            <div @mouseenter="activeMenu = 'config'" @mouseleave="activeMenu = null">
                                <a href="{{ route('project.service.configuration', $routeParams) }}"
                                    class="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                                    <span>Configuration</span>
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 5l7 7-7 7"></path>
                                    </svg>
                                </a>
                            </div>
                            <a href="{{ route('project.service.logs', $routeParams) }}"
                                class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                                Logs
                            </a>
                            @can('canAccessTerminal')
                                <a href="{{ route('project.service.command', $routeParams) }}"
                                    class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                                    Terminal
                                </a>
                            @endcan
                        @else
                            <!-- Database Main Menus -->
                            <div @mouseenter="activeMenu = 'config'" @mouseleave="activeMenu = null">
                                <a href="{{ route('project.database.configuration', $routeParams) }}"
                                    class="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                                    <span>Configuration</span>
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 5l7 7-7 7"></path>
                                    </svg>
                                </a>
                            </div>
                            <a href="{{ route('project.database.logs', $routeParams) }}"
                                class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                                Logs
                            </a>
                            @can('canAccessTerminal')
                                <a href="{{ route('project.database.command', $routeParams) }}"
                                    class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                                    Terminal
                                </a>
                            @endcan
                            @if (
                                $resourceType === 'App\Models\StandalonePostgresql' ||
                                    $resourceType === 'App\Models\StandaloneMongodb' ||
                                    $resourceType === 'App\Models\StandaloneMysql' ||
                                    $resourceType === 'App\Models\StandaloneMariadb')
                                <a href="{{ route('project.database.backup.index', $routeParams) }}"
                                    class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                                    Backups
                                </a>
                            @endif
                        @endif
                    </div>

                    <!-- Configuration Sub-menu -->
                    <div x-show="activeMenu === 'config'" x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                        x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0" @mouseenter="activeMenu = 'config'"
                        @mouseleave="activeMenu = null"
                        class="ml-1 w-52 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-coolgray-200 max-h-96 overflow-y-auto scrollbar">
                        @if ($isApplication)
                            <a href="{{ route('project.application.configuration', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">General</a>
                            <a href="{{ route('project.application.environment-variables', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Environment
                                Variables</a>
                            <a href="{{ route('project.application.persistent-storage', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Persistent
                                Storage</a>
                            <a href="{{ route('project.application.source', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Source</a>
                            <a href="{{ route('project.application.servers', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Servers</a>
                            <a href="{{ route('project.application.scheduled-tasks.show', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Scheduled
                                Tasks</a>
                            <a href="{{ route('project.application.webhooks', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Webhooks</a>
                            <a href="{{ route('project.application.preview-deployments', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Preview
                                Deployments</a>
                            <a href="{{ route('project.application.healthcheck', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Healthcheck</a>
                            <a href="{{ route('project.application.rollback', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Rollback</a>
                            <a href="{{ route('project.application.resource-limits', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Resource
                                Limits</a>
                            <a href="{{ route('project.application.resource-operations', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Resource
                                Operations</a>
                            <a href="{{ route('project.application.metrics', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Metrics</a>
                            <a href="{{ route('project.application.tags', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Tags</a>
                            <a href="{{ route('project.application.advanced', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Advanced</a>
                            <a href="{{ route('project.application.danger', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200 text-red-500">Danger
                                Zone</a>
                        @elseif ($isService)
                            <a href="{{ route('project.service.configuration', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">General</a>
                            <a href="{{ route('project.service.environment-variables', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Environment
                                Variables</a>
                            <a href="{{ route('project.service.storages', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Storages</a>
                            <a href="{{ route('project.service.scheduled-tasks.show', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Scheduled
                                Tasks</a>
                            <a href="{{ route('project.service.webhooks', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Webhooks</a>
                            <a href="{{ route('project.service.resource-operations', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Resource
                                Operations</a>
                            <a href="{{ route('project.service.tags', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Tags</a>
                            <a href="{{ route('project.service.danger', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200 text-red-500">Danger
                                Zone</a>
                        @else
                            <a href="{{ route('project.database.configuration', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">General</a>
                            <a href="{{ route('project.database.environment-variables', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Environment
                                Variables</a>
                            <a href="{{ route('project.database.servers', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Servers</a>
                            <a href="{{ route('project.database.persistent-storage', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Persistent
                                Storage</a>
                            <a href="{{ route('project.database.webhooks', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Webhooks</a>
                            <a href="{{ route('project.database.resource-limits', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Resource
                                Limits</a>
                            <a href="{{ route('project.database.resource-operations', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Resource
                                Operations</a>
                            <a href="{{ route('project.database.metrics', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Metrics</a>
                            <a href="{{ route('project.database.tags', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200">Tags</a>
                            <a href="{{ route('project.database.danger', $routeParams) }}"
                                class="block px-4 py-2 text-sm truncate hover:bg-coolgray-200 dark:hover:bg-coolgray-200 text-red-500">Danger
                                Zone</a>
                        @endif
                    </div>
                </div>
            </div>
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
