@props([
    'lastDeploymentInfo' => null,
    'lastDeploymentLink' => null,
    'resource' => null,
])
<nav class="flex pt-2 pb-10">
    <ol class="flex flex-wrap items-center gap-y-1">
        <!-- Project Level -->
        <li class="inline-flex items-center" x-data="{ projectOpen: false }">
            <div class="flex items-center relative">
                <a class="text-xs truncate lg:text-sm hover:text-warning"
                    href="{{ route('project.show', ['project_uuid' => data_get($resource, 'environment.project.uuid')]) }}">
                    {{ data_get($resource, 'environment.project.name', 'Undefined Name') }}
                </a>
                <button @mouseenter="projectOpen = true" @mouseleave="projectOpen = false"
                    class="ml-1 text-warning hover:text-warning-600 focus:outline-none">
                    <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-down': projectOpen }" fill="none"
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>

                <!-- Project Dropdown -->
                <div x-show="projectOpen" @mouseenter="projectOpen = true" @mouseleave="projectOpen = false"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                    class="absolute z-20 mt-40 w-48 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-coolgray-200">
                    <a href="{{ route('project.show', ['project_uuid' => data_get($resource, 'environment.project.uuid')]) }}" 
                        class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                        Overview
                    </a>
                    <a href="{{ route('project.edit', ['project_uuid' => data_get($resource, 'environment.project.uuid')]) }}" 
                        class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                        Settings
                    </a>
                    <a href="{{ route('project.resource.index', [
                            'environment_uuid' => data_get($resource, 'environment.uuid'),
                            'project_uuid' => data_get($resource, 'environment.project.uuid'),
                        ]) }}" 
                        class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                        All Resources
                    </a>
                </div>
            </div>
        </li>

        <!-- Environment Level -->
        <li class="inline-flex items-center" x-data="{ envOpen: false }">
            <div class="flex items-center relative">
                <a class="text-xs truncate lg:text-sm hover:text-warning"
                    href="{{ route('project.resource.index', [
                        'environment_uuid' => data_get($resource, 'environment.uuid'),
                        'project_uuid' => data_get($resource, 'environment.project.uuid'),
                    ]) }}">
                    {{ data_get($resource, 'environment.name') }}
                </a>
                <button @mouseenter="envOpen = true" @mouseleave="envOpen = false"
                    class="ml-1 text-warning hover:text-warning-600 focus:outline-none">
                    <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-down': envOpen }" fill="none" 
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>

                <!-- Environment Dropdown -->
                <div x-show="envOpen" @mouseenter="envOpen = true" @mouseleave="envOpen = false"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                    class="absolute z-20 mt-40 w-48 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-coolgray-200">
                    <a href="{{ route('project.resource.index', [
                            'environment_uuid' => data_get($resource, 'environment.uuid'),
                            'project_uuid' => data_get($resource, 'environment.project.uuid'),
                        ]) }}" 
                        class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                        All Resources
                    </a>
                    <a href="{{ route('project.environment.edit', [
                            'environment_uuid' => data_get($resource, 'environment.uuid'),
                            'project_uuid' => data_get($resource, 'environment.project.uuid'),
                        ]) }}" 
                        class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                        Environment Settings
                    </a>
                    <a href="{{ route('project.clone-me', [
                            'environment_uuid' => data_get($resource, 'environment.uuid'),
                            'project_uuid' => data_get($resource, 'environment.project.uuid'),
                        ]) }}" 
                        class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                        Clone Environment
                    </a>
                </div>
            </div>
        </li>

        <!-- Resource Level -->
        <li class="inline-flex items-center" x-data="{ resourceOpen: false }">
            <div class="flex items-center relative">
                <a class="text-xs truncate lg:text-sm hover:text-warning"
                    href="{{ $resource->getMorphClass() === 'App\Models\Application' 
                        ? route('project.application.configuration', [
                            'project_uuid' => data_get($resource, 'environment.project.uuid'),
                            'environment_uuid' => data_get($resource, 'environment.uuid'),
                            'application_uuid' => data_get($resource, 'uuid')
                        ])
                        : ($resource->getMorphClass() === 'App\Models\Service'
                            ? route('project.service.configuration', [
                                'project_uuid' => data_get($resource, 'environment.project.uuid'),
                                'environment_uuid' => data_get($resource, 'environment.uuid'),
                                'service_uuid' => data_get($resource, 'uuid')
                            ])
                            : route('project.database.configuration', [
                                'project_uuid' => data_get($resource, 'environment.project.uuid'),
                                'environment_uuid' => data_get($resource, 'environment.uuid'),
                                'database_uuid' => data_get($resource, 'uuid')
                            ]))
                    }}">
                    {{ data_get($resource, 'name') }}
                </a>
                <button @mouseenter="resourceOpen = true" @mouseleave="resourceOpen = false"
                    class="ml-1 text-warning hover:text-warning-600 focus:outline-none">
                    <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-down': resourceOpen }" fill="none" 
                        stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>

                <!-- Resource Dropdown -->
                <div x-show="resourceOpen" @mouseenter="resourceOpen = true" @mouseleave="resourceOpen = false"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                    class="absolute z-20 mt-50 w-48 bg-white dark:bg-coolgray-100 rounded-md shadow-lg py-1 border border-coolgray-200">
                    @if($resource->getMorphClass() === 'App\Models\Application')
                        <!-- Application Sections -->
                        <a href="{{ route('project.application.configuration', [
                                'project_uuid' => data_get($resource, 'environment.project.uuid'),
                                'environment_uuid' => data_get($resource, 'environment.uuid'),
                                'application_uuid' => data_get($resource, 'uuid')
                            ]) }}" 
                            class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                            Configuration
                        </a>
                        <a href="{{ route('project.application.deployment.index', [
                                'project_uuid' => data_get($resource, 'environment.project.uuid'),
                                'environment_uuid' => data_get($resource, 'environment.uuid'),
                                'application_uuid' => data_get($resource, 'uuid')
                            ]) }}" 
                            class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                            Deployments
                        </a>
                        <a href="{{ route('project.application.logs', [
                                'project_uuid' => data_get($resource, 'environment.project.uuid'),
                                'environment_uuid' => data_get($resource, 'environment.uuid'),
                                'application_uuid' => data_get($resource, 'uuid')
                            ]) }}" 
                            class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                            Logs
                        </a>
                        <a href="{{ route('project.application.command', [
                                'project_uuid' => data_get($resource, 'environment.project.uuid'),
                                'environment_uuid' => data_get($resource, 'environment.uuid'),
                                'application_uuid' => data_get($resource, 'uuid')
                            ]) }}" 
                            class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                            Terminal
                        </a>
                    @elseif(str_contains($resource->getMorphClass(), 'Database'))
                        <!-- Database Sections -->
                        <a href="{{ route('project.database.configuration', [
                                'project_uuid' => data_get($resource, 'environment.project.uuid'),
                                'environment_uuid' => data_get($resource, 'environment.uuid'),
                                'database_uuid' => data_get($resource, 'uuid')
                            ]) }}" 
                            class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                            Configuration
                        </a>
                        <a href="{{ route('project.database.backup.index', [
                                'project_uuid' => data_get($resource, 'environment.project.uuid'),
                                'environment_uuid' => data_get($resource, 'environment.uuid'),
                                'database_uuid' => data_get($resource, 'uuid')
                            ]) }}" 
                            class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                            Backups
                        </a>
                        <a href="{{ route('project.database.logs', [
                                'project_uuid' => data_get($resource, 'environment.project.uuid'),
                                'environment_uuid' => data_get($resource, 'environment.uuid'),
                                'database_uuid' => data_get($resource, 'uuid')
                            ]) }}" 
                            class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                            Logs
                        </a>
                        <a href="{{ route('project.database.command', [
                                'project_uuid' => data_get($resource, 'environment.project.uuid'),
                                'environment_uuid' => data_get($resource, 'environment.uuid'),
                                'database_uuid' => data_get($resource, 'uuid')
                            ]) }}" 
                            class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                            Terminal
                        </a>
                    @elseif($resource->getMorphClass() === 'App\Models\Service')
                        <!-- Service Sections -->
                        <a href="{{ route('project.service.configuration', [
                                'project_uuid' => data_get($resource, 'environment.project.uuid'),
                                'environment_uuid' => data_get($resource, 'environment.uuid'),
                                'service_uuid' => data_get($resource, 'uuid')
                            ]) }}" 
                            class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                            Configuration
                        </a>
                        <a href="{{ route('project.service.logs', [
                                'project_uuid' => data_get($resource, 'environment.project.uuid'),
                                'environment_uuid' => data_get($resource, 'environment.uuid'),
                                'service_uuid' => data_get($resource, 'uuid')
                            ]) }}" 
                            class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                            Logs
                        </a>
                        <a href="{{ route('project.service.command', [
                                'project_uuid' => data_get($resource, 'environment.project.uuid'),
                                'environment_uuid' => data_get($resource, 'environment.uuid'),
                                'service_uuid' => data_get($resource, 'uuid')
                            ]) }}" 
                            class="block px-4 py-2 text-sm hover:bg-coolgray-200 dark:hover:bg-coolgray-200">
                            Terminal
                        </a>
                    @endif
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
        transform: rotate(90deg); /* Arrow points downward */
    }
    .transition-transform {
        transition: transform 0.2s ease;
    }
</style>
